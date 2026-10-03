<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class CheckoutRuntimeBootstrapTest extends TestCase
{
    public function testFreshRuntimeUsesTheOwningCheckout(): void
    {
        $this->assertCurrentCheckout(false);
    }

    public function testRuntimeRebindsAnExistingForeignApplicationMapping(): void
    {
        $this->assertCurrentCheckout(true);
    }

    private function assertCurrentCheckout(bool $foreignMapping): void
    {
        $root = dirname(__DIR__);
        $code = '$root=getcwd();';
        if ($foreignMapping) {
            $code .= '$loader=require $root."/vendor/autoload.php";$loader->setPsr4("app\\\\",[sys_get_temp_dir()."/suxios-foreign-app"]);';
        }
        $code .= '$application=require $root."/bootstrap.php";echo json_encode(["root"=>$application->getRootPath(),"app"=>$application->getAppPath(),"source"=>(new ReflectionClass(app\\service\\BookingMonitoringService::class))->getFileName()],JSON_THROW_ON_ERROR);';
        $process = proc_open([PHP_BINARY, '-r', $code], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root, null, ['bypass_shell'=>true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $actual = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $normalize = static fn(string $path): string => strtolower(rtrim(str_replace('\\','/',$path),'/'));
        self::assertSame($normalize($root), $normalize($actual['root']));
        self::assertSame($normalize($root.'/app'), $normalize($actual['app']));
        self::assertSame($normalize($root.'/app/service/BookingMonitoringService.php'), $normalize($actual['source']));
    }
}
