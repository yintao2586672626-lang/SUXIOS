<?php
declare(strict_types=1);

namespace Tests;

use app\service\LocalMediaExtractionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class LocalMediaExtractionValidationTest extends TestCase
{
    use ReflectionHelper;

    #[DataProvider('invalidImages')]
    public function testInvalidImageOutputCannotBecomeReady(array $output): void
    {
        $result = $this->invokeNonPublic(new LocalMediaExtractionService(), 'parseImageContent', [json_encode($output)]);
        self::assertSame('failed', $result['status']);
        self::assertSame('vision_output_invalid', $result['error_code']);
        self::assertNull($result['text']);
        self::assertSame([], $result['structured']);
    }

    public static function invalidImages(): array
    {
        $valid = ['summary' => '测试截图', 'visible_text' => [], 'observable_facts' => [], 'uncertainties' => []];
        return [
            'missing lists' => [['summary' => '测试截图']],
            'wrong summary' => [array_replace($valid, ['summary' => ['nested']])],
            'wrong list' => [array_replace($valid, ['visible_text' => 'unverified text'])],
            'wrong item' => [array_replace($valid, ['observable_facts' => [12]])],
            'missing uncertainty' => [array_replace($valid, ['uncertainties' => null])],
        ];
    }

    public function testValidEmptyObservationsRemainReferenceOnly(): void
    {
        $result = $this->invokeNonPublic(new LocalMediaExtractionService(), 'parseImageContent', [json_encode([
            'summary' => '截图内容不清晰', 'visible_text' => [], 'observable_facts' => [], 'uncertainties' => ['数字无法辨认'],
        ])]);
        self::assertSame('ready', $result['status']);
        self::assertSame('截图内容不清晰', $result['text']);
        self::assertSame([], $result['structured']['observable_facts']);
        self::assertSame(['数字无法辨认'], $result['structured']['uncertainties']);
        self::assertSame('observable_image_content_only', $result['structured']['fact_use_boundary']);
        self::assertFalse($result['structured']['source_retained']);
    }
}
