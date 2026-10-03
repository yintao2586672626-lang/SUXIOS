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

    public function testOptimizedMappingPreservesTheFileContainingMultipleClasses(): void
    {
        $this->assertCurrentCheckout(true, true, false, true);
    }

    public function testStaleOptimizedMappingsAllowAbsentClassesAndCurrentPsr4FilesWithoutWarnings(): void
    {
        $this->assertCurrentCheckout(true, true, false, false, true);
    }

    public function testOutdatedExistingMappedFileDoesNotOverrideTheCurrentPsr4Class(): void
    {
        $this->assertCurrentCheckout(true, true, false, false, true, true);
    }

    private function assertCurrentCheckout(bool $foreignMapping, bool $optimizedMapping = false, bool $authoritative = false, bool $multipleClasses = false, bool $staleMapping = false, bool $outdatedExistingFile = false): void
    {
        $root = dirname(__DIR__);
        $foreignRoot = sys_get_temp_dir() . '/suxi-foreign-app-' . bin2hex(random_bytes(8));
        $foreignFile = $foreignRoot . '/service/BookingMonitoringService.php';
        $multipleClassFile = $foreignRoot . '/service/BrowserCaptureProcessRunner.php';
        if ($foreignMapping) {
            mkdir($foreignRoot . '/service', 0700, true);
            file_put_contents($foreignFile, "<?php\nnamespace app\\service;\nfinal class BookingMonitoringService {}\n");
            file_put_contents($multipleClassFile, "<?php\nnamespace app\\service;\nfinal class BrowserCaptureNativeProcessRuntime {}\n");
        }
        $staleForeignFile = $foreignRoot . '/service/' . ($outdatedExistingFile ? 'ChannelEconomicsService.php' : 'OldBookingMonitoringService.php');
        $removedForeignFile = $foreignRoot . '/service/RemovedApplicationFixture.php';
        if ($staleMapping) {
            file_put_contents($staleForeignFile, "<?php\nnamespace app\\service;\nfinal class BookingMonitoringService {}\n");
            file_put_contents($removedForeignFile, "<?php\nnamespace app\\service;\nfinal class CheckoutRemovedApplicationFixture {}\n");
        }
        try {
            $code = '$root=getcwd();';
            if ($foreignMapping) {
                $code .= '$loader=require $root."/vendor/autoload.php";$loader->setPsr4("app\\\\",[' . var_export($foreignRoot, true) . ']);';
            }
            if ($optimizedMapping) {
                $code .= '$loader->addClassMap([app\\service\\BookingMonitoringService::class=>'
                    . var_export($staleMapping ? $staleForeignFile : $foreignFile, true) . ']);';
            }
            if ($staleMapping) {
                $code .= '$loader->addClassMap([app\\service\\CheckoutRemovedApplicationFixture::class=>'
                    . var_export($removedForeignFile, true) . ']);'
                    . 'set_error_handler(static function($severity,$message){throw new ErrorException($message,0,$severity);});';
            }
            if ($multipleClasses) {
                $code .= '$loader->addClassMap([app\\service\\BrowserCaptureNativeProcessRuntime::class=>'
                    . var_export($foreignRoot . '/service/../service/BrowserCaptureProcessRunner.php', true) . ']);';
            }
            if ($authoritative) {
                // Supply framework dependencies while deliberately leaving local application classes unmapped.
                $code .= '$classmap=$loader->getClassMap();unset($classmap[app\\service\\ChannelEconomicsService::class]);'
                    . '(new ReflectionProperty($loader,"classMap"))->setValue($loader,$classmap);'
                    . '$framework=new think\\App($root);$loader->setClassMapAuthoritative(true);';
            }
            $code .= '$application=require $root."/bootstrap.php";';
            if ($staleMapping) {
                // Repeated initialization must also handle invalidated entries consistently.
                $code .= '$application=require $root."/bootstrap.php";$removedExists=class_exists(app\\service\\CheckoutRemovedApplicationFixture::class);';
            }
            if ($multipleClasses) {
                // Load the secondary class first: loading the primary class would conceal a broken map.
                $code .= '$multipleSource=(new ReflectionClass(app\\service\\BrowserCaptureNativeProcessRuntime::class))->getFileName();';
            }
            $code .= 'echo json_encode(["root"=>$application->getRootPath(),"app"=>$application->getAppPath(),"source"=>(new ReflectionClass(app\\service\\BookingMonitoringService::class))->getFileName(),"secondary_source"=>class_exists(app\\service\\ChannelEconomicsService::class)?(new ReflectionClass(app\\service\\ChannelEconomicsService::class))->getFileName():null,"multiple_source"=>$multipleSource??null,"removed_exists"=>$removedExists??null],JSON_THROW_ON_ERROR);';
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
            if ($multipleClasses) {
                self::assertSame($normalize($root.'/app/service/BrowserCaptureProcessRunner.php'), $normalize($actual['multiple_source']));
            }
            if ($staleMapping) {
                self::assertFalse($actual['removed_exists']);
                self::assertSame('', $error, 'stale map probes must not emit warnings');
            }
        } finally {
            if ($foreignMapping) {
                if ($staleMapping) {
                    unlink($staleForeignFile);
                    unlink($removedForeignFile);
                }
                unlink($foreignFile);
                unlink($multipleClassFile);
                rmdir($foreignRoot . '/service');
                rmdir($foreignRoot);
            }
        }
    }
}
