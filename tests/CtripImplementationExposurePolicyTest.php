<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripImplementationExposurePolicy;
use PHPUnit\Framework\TestCase;

final class CtripImplementationExposurePolicyTest extends TestCase
{
    public function testOnlySuperAdminMayViewCollectionImplementation(): void
    {
        $ordinary = new class {
            public function isSuperAdmin(): bool
            {
                return false;
            }
        };
        $superAdmin = new class {
            public function isSuperAdmin(): bool
            {
                return true;
            }
        };

        self::assertFalse(CtripImplementationExposurePolicy::canViewImplementation($ordinary));
        self::assertTrue(CtripImplementationExposurePolicy::canViewImplementation($superAdmin));
        self::assertFalse(CtripImplementationExposurePolicy::canViewImplementation(null));
    }

    public function testOrdinaryFieldResponseExcludesInterfacesPathsAndSampleValues(): void
    {
        $fields = CtripImplementationExposurePolicy::profileFields([[
            'id' => 7,
            'field_key' => 'competitor_rank',
            'field_name' => '竞争圈排名',
            'section' => 'competitor_rank',
            'status' => 'active',
            'source_interface' => 'privateEndpoint',
            'request_url' => 'https://example.invalid/private',
            'source_path' => 'data.items.0.rank',
            'mapping_path' => '$.data.items[*].rank',
            'latest_sample_value' => 3,
            'sample_values' => [3, 4],
        ]]);

        self::assertCount(1, $fields);
        self::assertSame('competitor_rank', $fields[0]['field_key']);
        self::assertSame('竞争圈排名', $fields[0]['field_name']);
        self::assertArrayNotHasKey('source_interface', $fields[0]);
        self::assertArrayNotHasKey('request_url', $fields[0]);
        self::assertArrayNotHasKey('source_path', $fields[0]);
        self::assertArrayNotHasKey('mapping_path', $fields[0]);
        self::assertArrayNotHasKey('latest_sample_value', $fields[0]);
        self::assertArrayNotHasKey('sample_values', $fields[0]);
    }

    public function testOrdinaryConfigAndCaptureResponsesExposeBusinessStateOnly(): void
    {
        $config = CtripImplementationExposurePolicy::config([
            'config_id' => 'ctrip_7',
            'name' => '携程门店',
            'system_hotel_id' => 7,
            'ctrip_hotel_id' => '9988',
            'credential_status' => 'configured',
            'url' => 'https://example.invalid/private',
            'node_id' => '24588',
            'approved_mappings_path' => 'private/mappings.json',
            'profile_dir' => 'private/profile',
        ]);
        $capture = CtripImplementationExposurePolicy::cookieCaptureResult([
            'status' => 'ready',
            'saved_count' => 12,
            'row_count' => 12,
            'responses' => [['url' => 'https://example.invalid/private']],
            'diagnosis_summary' => ['endpoint_id' => 'privateEndpoint'],
            'output' => 'private/output.json',
        ]);

        self::assertSame('ctrip_7', $config['config_id']);
        self::assertSame('9988', $config['ctrip_hotel_id']);
        self::assertSame('redacted', $config['implementation_visibility']);
        self::assertArrayNotHasKey('url', $config);
        self::assertArrayNotHasKey('node_id', $config);
        self::assertArrayNotHasKey('approved_mappings_path', $config);
        self::assertArrayNotHasKey('profile_dir', $config);

        self::assertSame('ready', $capture['status']);
        self::assertSame(12, $capture['saved_count']);
        self::assertSame('task_scoped', $capture['collection_contract']);
        self::assertArrayNotHasKey('responses', $capture);
        self::assertArrayNotHasKey('diagnosis_summary', $capture);
        self::assertArrayNotHasKey('output', $capture);
    }
}
