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

    public function testRuntimeRebindsAnOptimizedForeignApplicationClassmap(): void
    {
        $this->assertCurrentCheckout(true, true);
    }

    public function testRuntimeLoadsLocalClassesMissingFromASharedAuthoritativeClassmap(): void
    {
        $this->assertCurrentCheckout(true, true, true);
    }

    private function assertCurrentCheckout(bool $foreignMapping, bool $optimizedMapping = false, bool $authoritative = false): void
    {
        $root = dirname(__DIR__);
        $foreignFile = $optimizedMapping ? tempnam(sys_get_temp_dir(), 'suxi-foreign-app-') : null;
        if ($optimizedMapping) {
            self::assertIsString($foreignFile);
            file_put_contents($foreignFile, "<?php\nnamespace app\\service;\nfinal class BookingMonitoringService {}\n");
        }
        try {
            $code = '$root=getcwd();';
            if ($foreignMapping) {
                $code .= '$loader=require $root."/vendor/autoload.php";$loader->setPsr4("app\\\\",[sys_get_temp_dir()."/suxios-foreign-app"]);';
            }
            if ($optimizedMapping) {
                $code .= '$loader->addClassMap([app\\service\\BookingMonitoringService::class=>'
                    . var_export($foreignFile, true) . ']);';
            }
            if ($authoritative) {
                // Supply framework dependencies while deliberately leaving local application classes unmapped.
                $code .= '$classmap=$loader->getClassMap();unset($classmap[app\\service\\ChannelEconomicsService::class]);'
                    . '(new ReflectionProperty($loader,"classMap"))->setValue($loader,$classmap);'
                    . '$framework=new think\\App($root);$loader->setClassMapAuthoritative(true);';
            }
            $code .= '$application=require $root."/bootstrap.php";echo json_encode(["root"=>$application->getRootPath(),"app"=>$application->getAppPath(),"source"=>(new ReflectionClass(app\\service\\BookingMonitoringService::class))->getFileName(),"secondary_source"=>class_exists(app\\service\\ChannelEconomicsService::class)?(new ReflectionClass(app\\service\\ChannelEconomicsService::class))->getFileName():null],JSON_THROW_ON_ERROR);';
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
            self::assertSame($normalize($root.'/app/service/ChannelEconomicsService.php'), $normalize((string)$actual['secondary_source']));
        } finally {
            if (is_string($foreignFile) && is_file($foreignFile)) unlink($foreignFile);
        }
    }
}
