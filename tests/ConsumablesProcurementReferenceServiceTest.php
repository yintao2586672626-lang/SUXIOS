<?php
declare(strict_types=1);

namespace Tests;

use app\service\ConsumablesProcurementReferenceService as Reference;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConsumablesProcurementReferenceServiceTest extends TestCase
{
    public function testCatalogPreservesAllOriginalQuotesSpecificationsAndCorrectedLimits(): void
    {
        $catalog = (new Reference())->catalog();
        self::assertSame('consumables-procurement-reference-v1', $catalog['schema_version']);
        self::assertCount(27, $catalog['items']);
        self::assertSame(135, array_sum(array_map(static fn(array $i): int => count($i['quotes']), $catalog['items'])));
        self::assertNull($catalog['currency']);
        self::assertNull($catalog['supplier']);
        self::assertNull($catalog['quote_effective_date']);
        self::assertSame('reference_only', $catalog['usage_policy']);
        self::assertSame('50G卷纸', $catalog['items'][20]['quotes'][0]['raw_text']);
        self::assertSame('specification_only', $catalog['items'][20]['quotes'][0]['quote_kind']);
        self::assertNull($catalog['items'][20]['quotes'][0]['amount']);
        self::assertStringContainsString('具体款式、是否配套及投放量待确认', $catalog['items'][0]['interpretation_limits'][1]);
        self::assertStringContainsString('7.5kg', $catalog['items'][19]['interpretation_limits'][1]);
        self::assertSame('330-360', $catalog['items'][23]['quotes'][1]['raw_text']);
    }

    public function testCanonicalSnapshotIgnoresClientTextQualityAndIsIdempotent(): void
    {
        $service = new Reference();
        $snapshot = $service->normalizeReference(self::identity() + ['raw_text' => '免费0元', 'usage_policy' => 'approved', 'source_label' => '已成交']);
        self::assertSame('0.38以下', $snapshot['raw_text']);
        self::assertNull($snapshot['amount']);
        self::assertNull($snapshot['package_quantity']);
        self::assertSame('reference_only', $snapshot['usage_policy']);
        self::assertSame('采购推荐价参考', $snapshot['source_label']);
        self::assertFalse($snapshot['confirmed_for_scenario']);
        self::assertSame($snapshot, $service->normalizeReference($snapshot));
    }

    public function testInvalidIdentityAndNonBooleanConfirmationFail(): void
    {
        foreach ([['catalog_id' => 'foreign'], ['source_sha256' => str_repeat('0', 64)], ['item_id' => 28], ['item_id' => '1'],
            ['tier_id' => 'x'], ['confirmed_for_scenario' => 1], ['confirmed_for_scenario' => null]] as $change) {
            try { (new Reference())->normalizeReference(array_replace(self::identity(), $change)); self::fail('Invalid reference must fail'); }
            catch (InvalidArgumentException $error) { self::assertNotSame('', $error->getMessage()); }
        }
    }

    public function testMissingOrTamperedSourceFailsUnavailableInsteadOfEmptyCatalog(): void
    {
        $directory = sys_get_temp_dir() . '/consumables-reference-test-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            foreach ([false, true] as $tampered) {
                if ($tampered) file_put_contents($directory . '/source-manifest.json', '{"retained_files":[]}');
                try { (new Reference($directory))->catalog(); self::fail('Missing or changed source must fail'); }
                catch (RuntimeException $error) { self::assertSame(503, $error->getCode()); self::assertStringNotContainsString($directory, $error->getMessage()); }
            }
        } finally {
            if (is_file($directory . '/source-manifest.json')) unlink($directory . '/source-manifest.json');
            rmdir($directory);
        }
    }

    public static function identity(): array
    {
        return ['catalog_id' => Reference::CATALOG_ID, 'source_sha256' => Reference::SOURCE_SHA256,
            'item_id' => 1, 'tier_id' => 'c', 'confirmed_for_scenario' => false];
    }
}
