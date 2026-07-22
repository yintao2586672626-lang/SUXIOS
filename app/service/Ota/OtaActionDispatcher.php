<?php
declare(strict_types=1);

namespace app\service\Ota;

use app\domain\Ota\OtaActionCatalog;
use LogicException;
use think\Response;

final class OtaActionDispatcher
{
    /**
     * @param list<mixed> $arguments
     */
    public function dispatch(
        string $domain,
        string $action,
        OtaActionHandler $handler,
        array $arguments = []
    ): Response
    {
        OtaActionCatalog::assertOwned($domain, $action);

        if (!is_callable([$handler, $action])) {
            throw new LogicException("OTA action handler is missing public action {$action}");
        }

        $response = $handler->{$action}(...$arguments);
        if (!$response instanceof Response) {
            throw new LogicException("OTA action {$action} must return a ThinkPHP response");
        }

        return $response;
    }
}
