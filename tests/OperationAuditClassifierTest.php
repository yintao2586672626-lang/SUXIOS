<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationAuditClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationAuditClassifierTest extends TestCase
{
    private OperationAuditClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new OperationAuditClassifier();
    }

    #[DataProvider('auditedPathProvider')]
    public function testClassifiesAuditedPaths(string $method, string $uri, string $module, string $action, string $category): void
    {
        $result = $this->classifier->classify($method, $uri);

        self::assertIsArray($result);
        self::assertSame($module, $result['module']);
        self::assertSame($action, $result['action']);
        self::assertSame($category, $result['category']);
        self::assertStringStartsWith('api/', $result['path']);
    }

    public function testSkipsExcludedAndManuallyLoggedPaths(): void
    {
        self::assertNull($this->classifier->classify('GET', '/api/operation-logs'));
        self::assertNull($this->classifier->classify('GET', '/api/health'));
        self::assertNull($this->classifier->classify('POST', '/api/online-data/fetch-ctrip'));
        self::assertNull($this->classifier->classify('POST', '/api/agent/feasibility-report/regenerate/15'));
    }

    public function testSkipsUnsupportedPathsAndMethods(): void
    {
        self::assertNull($this->classifier->classify('GET', '/api/not-a-module'));
        self::assertNull($this->classifier->classify('POST', '/api/operation/plain-save'));
        self::assertNull($this->classifier->classify('GET', ''));
    }

    public static function auditedPathProvider(): array
    {
        return [
            'online analysis with query string' => ['GET', '/api/online-data/data-analysis?dimension=day', 'online_data', 'analyze_data', 'analysis'],
            'operation read path' => ['GET', '/api/operation/full-data', 'operation', 'view_data', 'acquisition'],
            'strategy simulation post' => ['POST', '/api/strategy/simulate', 'strategy', 'analyze_data', 'analysis'],
            'admin competitor nested path' => ['GET', '/api/admin/competitor-price-logs/12/detail', 'competitor', 'view_data', 'acquisition'],
            'transfer dashboard path' => ['GET', '/api/transfer/dashboard', 'transfer', 'analyze_data', 'analysis'],
        ];
    }
}
