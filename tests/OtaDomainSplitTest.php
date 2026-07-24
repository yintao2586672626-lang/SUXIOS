<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use app\controller\ota\CredentialController;
use app\controller\ota\CtripController;
use app\controller\ota\MeituanController;
use app\controller\ota\ProfileController;
use app\controller\ota\SyncController;
use app\domain\Ota\OtaActionCatalog;
use app\domain\Ota\OtaDomain;
use app\model\Role;
use app\model\User;
use app\service\Ota\CredentialService;
use app\service\Ota\CtripService;
use app\service\Ota\MeituanService;
use app\service\Ota\OtaActionDispatcher;
use app\service\Ota\OtaActionHandler;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionType;
use think\App;
use think\Response;

final class OtaDomainSplitTest extends TestCase
{
    private const LEGACY_ROUTE_SURFACE_COUNT = 108;
    private const LEGACY_ROUTE_SURFACE_SHA256 = 'c5d08327f19136021f51355fd287e9b3bcf19995fb1c45c8686134287806806f';

    private const CONTROLLERS = [
        OtaDomain::CTRIP => [CtripController::class, 'ota.CtripController'],
        OtaDomain::MEITUAN => [MeituanController::class, 'ota.MeituanController'],
        OtaDomain::CREDENTIAL => [CredentialController::class, 'ota.CredentialController'],
        OtaDomain::PROFILE => [ProfileController::class, 'ota.ProfileController'],
        OtaDomain::SYNC => [SyncController::class, 'ota.SyncController'],
    ];

    public function testEveryDomainActionUsesAnExplicitRouteAdapterWithoutDependingOnLegacyController(): void
    {
        $routeSource = (string)file_get_contents(dirname(__DIR__) . '/route/app.php');
        $seenActions = [];

        self::assertSame(OtaActionHandler::class, (new ReflectionClass(OnlineData::class))->getParentClass()?->getName());

        foreach (OtaActionCatalog::all() as $domain => $actions) {
            [$controller, $routeController] = self::CONTROLLERS[$domain];
            $controllerSource = (string)file_get_contents((new ReflectionClass($controller))->getFileName());
            $declaredPublicActions = [];

            foreach ((new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $controller && !$method->isConstructor()) {
                    $declaredPublicActions[] = $method->getName();
                }
            }

            sort($declaredPublicActions);
            $expectedActions = $actions;
            sort($expectedActions);
            self::assertSame($expectedActions, $declaredPublicActions, "{$domain} controller surface drifted");

            foreach ($actions as $action) {
                self::assertArrayNotHasKey($action, $seenActions, "{$action} belongs to more than one OTA domain");
                $seenActions[$action] = $domain;

                self::assertTrue(method_exists(OtaActionHandler::class, $action), "OTA handler action {$action} is missing");
                self::assertTrue(method_exists(OnlineData::class, $action), "Legacy OnlineData::{$action} is missing");
                self::assertStringContainsString(
                    "'{$routeController}/{$action}'",
                    $routeSource,
                    "{$action} is not routed through its domain controller"
                );
                self::assertStringNotContainsString(
                    "'OnlineData/{$action}'",
                    $routeSource,
                    "{$action} still bypasses its domain controller"
                );
            }

            self::assertStringNotContainsString('OnlineData', $controllerSource);
            self::assertStringNotContainsString('legacyOnlineData', $controllerSource);
        }
    }

    public function testPlatformServicesExposeTheirDomainActionsAndExecuteTheInternalHandler(): void
    {
        $app = $this->authenticatedApp();
        $handler = new OtaActionHandler($app);
        $services = [
            OtaDomain::CTRIP => [$app->make(CtripService::class, [$handler], true), 'captureCtripBrowserData', [[]]],
            OtaDomain::MEITUAN => [$app->make(MeituanService::class, [$handler], true), 'captureMeituanBrowserData', [[]]],
            OtaDomain::CREDENTIAL => [$app->make(CredentialService::class, [$handler], true), 'getCookiesList', []],
        ];

        foreach ($services as $domain => [$service, $action, $arguments]) {
            self::assertSame(OtaActionCatalog::actionsFor($domain), $service->actions());
            self::assertInstanceOf(Response::class, $service->execute($action, $arguments));
        }
    }

    public function testAuthenticatedDomainControllersPreserveLegacyResponses(): void
    {
        $app = $this->authenticatedApp();
        $cases = [
            [CtripController::class, 'captureCtripBrowserData', [[]]],
            [MeituanController::class, 'captureMeituanBrowserData', [[]]],
            [CredentialController::class, 'getCookiesList', []],
            [ProfileController::class, 'platformProfileStatus', []],
            [SyncController::class, 'collectionStatus', []],
        ];

        foreach ($cases as [$controllerClass, $action, $arguments]) {
            $legacyResponse = (new OnlineData($app))->{$action}(...$arguments);
            $domainResponse = (new $controllerClass($app))->{$action}(...$arguments);

            self::assertSame(
                $this->responseSnapshot($legacyResponse),
                $this->responseSnapshot($domainResponse),
                "{$controllerClass}::{$action} changed the legacy response"
            );
        }
    }

    public function testAServiceCannotExecuteAnActionOwnedByAnotherDomain(): void
    {
        $this->expectException(DomainException::class);

        (new CtripService(
            new OtaActionHandler($this->authenticatedApp()),
            new OtaActionDispatcher()
        ))->execute(
            OtaActionCatalog::actionsFor(OtaDomain::MEITUAN)[0],
            []
        );
    }

    public function testEveryDomainControllerPreservesLegacyMethodSignatures(): void
    {
        foreach (OtaActionCatalog::all() as $domain => $actions) {
            [$controller] = self::CONTROLLERS[$domain];

            foreach ($actions as $action) {
                $legacy = new ReflectionMethod(OnlineData::class, $action);
                $split = new ReflectionMethod($controller, $action);

                self::assertSame(
                    $this->methodSignature($legacy),
                    $this->methodSignature($split),
                    "{$controller}::{$action} changed the legacy PHP method contract"
                );
            }
        }
    }

    public function testLegacyHttpMethodPathAndActionSurfaceIsUnchanged(): void
    {
        $routeSource = (string)file_get_contents(dirname(__DIR__) . '/route/app.php');
        $surface = $this->normalizedLegacyOtaRouteSurface($routeSource);

        self::assertCount(self::LEGACY_ROUTE_SURFACE_COUNT, $surface);
        self::assertSame(
            self::LEGACY_ROUTE_SURFACE_SHA256,
            hash('sha256', implode("\n", $surface)),
            "Legacy OTA HTTP route surface changed:\n" . implode("\n", $surface)
        );
    }

    public function testDomainLayerDoesNotDependOnControllerServiceOrFrameworkLayers(): void
    {
        $files = glob(dirname(__DIR__) . '/app/domain/Ota/*.php') ?: [];
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string)file_get_contents($file);
            self::assertStringNotContainsString('app\\controller', $source, $file);
            self::assertStringNotContainsString('app\\service', $source, $file);
            self::assertStringNotContainsString('think\\', $source, $file);
        }
    }

    private function authenticatedApp(): App
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        restore_error_handler();
        restore_exception_handler();

        $admin = new User();
        $admin->id = 1;
        $admin->tenant_id = 0;
        $admin->role_id = Role::SUPER_ADMIN;
        $app->request->user = $admin;

        return $app;
    }

    /**
     * @return array{
     *     returns_reference:bool,
     *     return_type:array{type:string,allows_null:bool}|null,
     *     parameters:list<array<string, mixed>>
     * }
     */
    private function methodSignature(ReflectionMethod $method): array
    {
        return [
            'returns_reference' => $method->returnsReference(),
            'return_type' => $this->typeSignature($method->getReturnType()),
            'parameters' => array_map(
                fn(ReflectionParameter $parameter): array => [
                    'name' => $parameter->getName(),
                    'type' => $this->typeSignature($parameter->getType()),
                    'optional' => $parameter->isOptional(),
                    'variadic' => $parameter->isVariadic(),
                    'by_reference' => $parameter->isPassedByReference(),
                    'has_default' => $parameter->isDefaultValueAvailable(),
                    'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                    'default_constant' => $parameter->isDefaultValueAvailable()
                        && $parameter->isDefaultValueConstant()
                        ? $parameter->getDefaultValueConstantName()
                        : null,
                ],
                $method->getParameters()
            ),
        ];
    }

    /** @return array{type:string,allows_null:bool}|null */
    private function typeSignature(?ReflectionType $type): ?array
    {
        if ($type === null) {
            return null;
        }

        return [
            'type' => (string)$type,
            'allows_null' => $type->allowsNull(),
        ];
    }

    /** @return list<string> */
    private function normalizedLegacyOtaRouteSurface(string $source): array
    {
        $actions = [];
        foreach (OtaActionCatalog::all() as $domainActions) {
            foreach ($domainActions as $action) {
                $actions[$action] = true;
            }
        }

        $surface = [];
        $insideAuthenticatedGroup = false;
        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            if (str_contains($line, "Route::group('api/online-data'")) {
                $insideAuthenticatedGroup = true;
            }

            if (preg_match("~'(?:ota\\.[A-Za-z]+Controller|OnlineData)/([A-Za-z0-9_]+)'~", $line, $match)
                && isset($actions[$match[1]])) {
                $normalized = preg_replace(
                    "~'(?:ota\\.[A-Za-z]+Controller|OnlineData)/" . preg_quote($match[1], '~') . "'~",
                    "'OnlineData/{$match[1]}'",
                    trim($line)
                );
                $surface[] = ($insideAuthenticatedGroup ? 'protected' : 'global')
                    . '|'
                    . preg_replace('/\s+/', ' ', (string)$normalized);
            }

            if ($insideAuthenticatedGroup
                && str_contains($line, '})->middleware(\\app\\middleware\\Auth::class);')) {
                $insideAuthenticatedGroup = false;
            }
        }

        sort($surface);

        return $surface;
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    private function responseSnapshot(Response $response): array
    {
        $payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        unset($payload['time']);

        return [
            'status' => $response->getCode(),
            'payload' => $payload,
        ];
    }
}
