<?php
declare(strict_types=1);

namespace app\controller\ota;

use app\controller\Base;
use app\service\Ota\OtaActionDispatcher;
use app\service\Ota\OtaActionHandler;
use think\Response;

/**
 * Shared request-scoped infrastructure for explicit OTA domain controllers.
 */
abstract class OtaController extends Base
{
    private ?OtaActionHandler $otaActionHandler = null;
    private ?OtaActionDispatcher $domainDispatcher = null;

    final protected function otaActionHandler(): OtaActionHandler
    {
        return $this->otaActionHandler ??= $this->app->make(OtaActionHandler::class, [], true);
    }

    /**
     * @param list<mixed> $arguments
     */
    final protected function executeDomainAction(string $domain, string $action, array $arguments = []): Response
    {
        $this->domainDispatcher ??= $this->app->make(OtaActionDispatcher::class, [], true);

        return $this->domainDispatcher->dispatch($domain, $action, $this->otaActionHandler(), $arguments);
    }
}
