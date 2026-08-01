<?php
declare(strict_types=1);

namespace Tests;

use app\service\OutboundUrlGuard;
use app\service\platform\ApiDataSourceAdapter;
use PHPUnit\Framework\TestCase;

final class ApiDataSourceAdapterSsrfTest extends TestCase
{
    public function testRejectsMixedPublicAndPrivateDnsAnswersBeforeSendingSecrets(): void
    {
        $adapter = new ApiDataSourceAdapter(new OutboundUrlGuard(
            static fn(string $host): array => $host === 'mixed.example'
                ? ['93.184.216.34', '10.20.30.40']
                : []
        ));

        $result = $adapter->fetch([
            'ingestion_method' => 'api',
            'config' => [
                'url' => 'https://mixed.example/data',
                'allowed_hosts' => ['mixed.example'],
            ],
            'secret' => ['api_key' => 'must-not-be-sent'],
        ]);

        self::assertSame('failed', $result['status']);
        self::assertSame([], $result['payload']);
        self::assertStringNotContainsString('must-not-be-sent', $result['message']);
    }

    public function testKeepsExactAllowedHostsRestrictionAfterPublicAddressValidation(): void
    {
        $adapter = new ApiDataSourceAdapter(new OutboundUrlGuard(
            static fn(string $host): array => ['93.184.216.34']
        ));

        $result = $adapter->fetch([
            'ingestion_method' => 'api',
            'config' => [
                'url' => 'https://public.example/data',
                'allowed_hosts' => ['approved.example'],
            ],
        ]);

        self::assertSame('failed', $result['status']);
        self::assertSame('API source host is outside allowed_hosts.', $result['message']);
    }
}
