<?php
declare(strict_types=1);

namespace app\controller\ota;

use app\domain\Ota\OtaDomain;
use think\Response;

final class SyncController extends OtaController
{
    public function collectionResourceCatalog(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function collectionStatus(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function dataSourceList(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function syncDataSource(int $id): Response
    {
        return $this->execute(__FUNCTION__, [$id]);
    }

    public function saveDataSource(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function deleteDataSource(int $id): Response
    {
        return $this->execute(__FUNCTION__, [$id]);
    }

    public function importDataSourceRows(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function importBrowserAssistCapture(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function syncTaskList(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function syncLogList(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function manualFetchTaskStatus(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function autoFetch(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function autoFetchStatus(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function autoFetchRecords(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function batchDeleteAutoFetchRecords(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function clearAutoFetchRecords(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function toggleAutoFetch(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function setFetchSchedule(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function retryAutoFetch(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    public function cronTrigger(): Response
    {
        return $this->execute(__FUNCTION__);
    }

    /** @param list<mixed> $arguments */
    private function execute(string $action, array $arguments = []): Response
    {
        return $this->executeDomainAction(OtaDomain::SYNC, $action, $arguments);
    }
}
