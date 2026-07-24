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
        self::assertNull($this->classifier->classify('POST', '/api/hotels'));
        self::assertNull($this->classifier->classify('PUT', '/api/users/12'));
        self::assertNull($this->classifier->classify('DELETE', '/api/roles/3'));
        self::assertNull($this->classifier->classify('POST', '/api/online-data/save-cookies'));
        self::assertNull($this->classifier->classify('DELETE', '/api/online-data/delete-ctrip-config?id=8'));
    }

    public function testClassifiesFailuresForManualAndAuthPathsWithoutChangingSuccessDeduplication(): void
    {
        self::assertNull($this->classifier->classify('PUT', '/api/users/12'));
        self::assertNull($this->classifier->classify('POST', '/api/auth/changePassword'));
        self::assertNull($this->classifier->classify('POST', '/api/online-data/save-ctrip-config'));
        self::assertNull($this->classifier->classify('POST', '/api/online-data/save-meituan-config'));
        self::assertNull($this->classifier->classify('POST', '/api/admin/competitor-devices'));

        $manualFailure = $this->classifier->classifyFailure('PUT', '/api/users/12');
        self::assertIsArray($manualFailure);
        self::assertSame('user', $manualFailure['module']);
        self::assertSame('save_form', $manualFailure['action']);
        self::assertSame('api/users/12', $manualFailure['path']);

        $authFailure = $this->classifier->classifyFailure('POST', '/api/auth/changePassword');
        self::assertIsArray($authFailure);
        self::assertSame('auth', $authFailure['module']);
        self::assertSame('save_form', $authFailure['action']);
        self::assertSame('api/auth/changepassword', $authFailure['path']);

        self::assertIsArray($this->classifier->classifyFailure('POST', '/api/online-data/save-ctrip-config'));
        self::assertIsArray($this->classifier->classifyFailure('POST', '/api/online-data/save-meituan-config'));
        self::assertIsArray($this->classifier->classifyFailure('POST', '/api/admin/competitor-devices'));

        self::assertNull($this->classifier->classifyFailure('GET', '/api/health'));
        self::assertNull($this->classifier->classifyFailure('GET', '/api/operation-logs'));
    }

    public function testSkipsUnsupportedPathsAndMethods(): void
    {
        self::assertNull($this->classifier->classify('GET', '/api/not-a-module'));
        self::assertNull($this->classifier->classify('POST', '/api/operation/plain-save'));
        self::assertNull($this->classifier->classify('GET', ''));
    }

    #[DataProvider('formOperationPathProvider')]
    public function testClassifiesFormSaveAndArchiveOperations(string $method, string $uri, string $module, string $action): void
    {
        $result = $this->classifier->classify($method, $uri);

        self::assertIsArray($result);
        self::assertSame($module, $result['module']);
        self::assertSame($action, $result['action']);
        self::assertSame('operation', $result['category']);
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

    public static function formOperationPathProvider(): array
    {
        return [
            'wechat robot api save form' => ['POST', '/api/admin/competitor-wechat-robot/save', 'competitor', 'save_form'],
            'opening project archive form' => ['DELETE', '/api/opening/projects/8', 'opening', 'archive_form'],
            'opening task update form' => ['PUT', '/api/opening/tasks/18', 'opening', 'save_form'],
            'operation action create form' => ['POST', '/api/operation/actions', 'operation', 'save_form'],
            'public page diagnosis task bridge' => ['POST', '/api/online-data/public-page-diagnosis/execution-intent', 'online_data', 'save_form'],
        ];
    }
}
