<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\PlatformDataSyncService;
use think\Response;
use think\facade\Db;

trait PlatformDataSourceConcern
{
    public function dataSourceList(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $service = new PlatformDataSyncService();
            return $this->success($service->listDataSources($this->currentUser, $this->request->get()));
        } catch (\Throwable $e) {
            return $this->error('获取数据源失败: ' . $e->getMessage(), 500);
        }
    }

    public function collectionResourceCatalog(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $service = new PlatformDataSyncService();
            return $this->success($service->collectionResourceCatalog($this->currentUser, $this->request->get()));
        } catch (\Throwable $e) {
            return $this->error('Failed to load collection resource catalog: ' . $e->getMessage(), 500);
        }
    }

    public function saveDataSource(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $service = new PlatformDataSyncService();
            $data = $service->saveDataSource($this->currentUser, $this->requestData());
            $this->clearAutoFetchLightProfileSourcesCache((int)($data['system_hotel_id'] ?? 0), (string)($data['platform'] ?? ''));
            OperationLog::record('online_data', 'save_data_source', '保存平台数据源: ' . ($data['name'] ?? ''), $this->currentUser->id, (int)($data['system_hotel_id'] ?? 0) ?: null);
            return $this->success($data, '数据源保存成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('保存数据源失败: ' . $e->getMessage(), 500);
        }
    }

    public function deleteDataSource(int $id): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        try {
            $source = Db::name('platform_data_sources')->where('id', $id)->find();
            $service = new PlatformDataSyncService();
            $service->deleteDataSource($this->currentUser, (int)$id);
            $this->clearBrowserProfileStatusCacheForSource(is_array($source) ? $source : []);
            $this->clearAutoFetchLightProfileSourcesCache((int)($source['system_hotel_id'] ?? 0), (string)($source['platform'] ?? ''));
            OperationLog::record('online_data', 'delete_data_source', '停用平台数据源ID: ' . $id, $this->currentUser->id);
            return $this->success(['id' => (int)$id], '数据源已停用');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('删除数据源失败: ' . $e->getMessage(), 500);
        }
    }

    public function syncDataSource(int $id): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $service = new PlatformDataSyncService();
            $result = $service->syncDataSource($this->currentUser, (int)$id, $this->requestData());
            OperationLog::record('online_data', 'sync_data_source', '同步平台数据源ID: ' . $id . '，状态: ' . $result['status'], $this->currentUser->id, null);
            return $this->success($result, '同步任务已完成');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('同步数据源失败: ' . $e->getMessage(), 500);
        }
    }

    public function importDataSourceRows(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $service = new PlatformDataSyncService();
            $payload = $this->requestData();
            $file = $this->request->file('file') ?: $this->request->file('import_file');
            if ($file) {
                $payload['rows'] = $service->parseImportFile($file->getPathname(), $file->getOriginalName());
            }
            $result = $service->importRows($this->currentUser, $payload);
            OperationLog::record('online_data', 'import_data_source_rows', '导入平台数据，状态: ' . $result['status'], $this->currentUser->id, null);
            return $this->success($result, '导入任务已完成');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('导入数据失败: ' . $e->getMessage(), 500);
        }
    }

    public function syncTaskList(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $service = new PlatformDataSyncService();
            return $this->success($service->listSyncTasks($this->currentUser, $this->request->get()));
        } catch (\Throwable $e) {
            return $this->error('获取同步任务失败: ' . $e->getMessage(), 500);
        }
    }

    public function syncLogList(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $service = new PlatformDataSyncService();
            return $this->success($service->listSyncLogs($this->currentUser, $this->request->get()));
        } catch (\Throwable $e) {
            return $this->error('获取同步日志失败: ' . $e->getMessage(), 500);
        }
    }

}
