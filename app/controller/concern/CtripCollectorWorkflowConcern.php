<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\CtripCollectorWorkflowService;
use think\facade\Db;
use think\Response;

trait CtripCollectorWorkflowConcern
{
    public function ctripCollectorContract(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        $query = $this->request->get();
        $sourceId = (int)($query['source_id'] ?? $query['sourceId'] ?? 0);
        $source = [];
        if ($sourceId > 0) {
            $row = Db::name('platform_data_sources')->where('id', $sourceId)->find();
            if (!$row) {
                return $this->error('Ctrip data source not found.', 404);
            }
            $config = json_decode((string)($row['config_json'] ?? ''), true);
            $row['config'] = is_array($config) ? $config : [];
            unset($row['secret_json']);
            $source = $row;
        }

        $contract = (new CtripCollectorWorkflowService())->buildContract($source, $query);
        return $this->success($contract, 'Ctrip collector contract loaded.');
    }
}
