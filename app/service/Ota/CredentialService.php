<?php
declare(strict_types=1);

namespace app\service\Ota;

use app\domain\Ota\OtaActionCatalog;
use app\domain\Ota\OtaDomain;
use think\Response;

final class CredentialService
{
    private OtaActionHandler $handler;
    private OtaActionDispatcher $dispatcher;

    public function __construct(OtaActionHandler $handler, OtaActionDispatcher $dispatcher)
    {
        $this->handler = $handler;
        $this->dispatcher = $dispatcher;
    }

    /** @param list<mixed> $arguments */
    public function execute(string $action, array $arguments = []): Response
    {
        return $this->dispatcher->dispatch(OtaDomain::CREDENTIAL, $action, $this->handler, $arguments);
    }

    /** @return list<string> */
    public function actions(): array
    {
        return OtaActionCatalog::actionsFor(OtaDomain::CREDENTIAL);
    }
}
