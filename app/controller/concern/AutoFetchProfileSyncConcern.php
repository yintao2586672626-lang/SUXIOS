<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\model\SystemNotification;
use app\service\BrowserProfileCaptureRequestService;
use app\service\CtripCollectorWorkflowService;
use app\service\OtaProfileBindingService;
use app\service\OtaProfileSessionProofService;
use app\service\OtaFailureNotificationService;
use app\service\OnlineDailyDataPersistenceService;
use app\service\PlatformProfileBindingReadinessService;
use app\service\PlatformDataSyncService;
use app\service\ScheduledAutoFetchPolicy;
use think\Response;
use think\facade\Db;

trait AutoFetchProfileSyncConcern
{
    private function syncCtripBrowserProfileDataSourcesForAutoFetch(int $hotelId, string $dataDate, bool $interactiveBrowser, ?array $sources = null, array $periodOptions = []): array
    {
        $sources = $sources ?? $this->listEnabledCtripBrowserProfileDataSources($hotelId);
        $sources = $this->filterCollectableBrowserProfileDataSources($sources, 'ctrip');
        $sources = $this->selectCurrentBrowserProfileDataSources($sources);
        if (empty($sources)) {
            return [
                'attempted' => false,
                'success' => false,
                'saved_count' => 0,
                'message' => '',
            ];
        }

        $service = new PlatformDataSyncService();
        $savedCount = 0;
        $messages = [];
        $timing = [];
        $syncResults = [];
        foreach ($sources as $source) {
            $syncOptions = [
                'trigger_type' => 'auto_fetch',
                'data_date' => $dataDate,
                'interactive_browser' => $interactiveBrowser,
                'data_period' => $periodOptions['data_period'] ?? 'historical_daily',
                'snapshot_time' => $periodOptions['snapshot_time'] ?? date('Y-m-d H:i:s'),
                'ctrip_section_concurrency' => $periodOptions['ctrip_section_concurrency'] ?? 3,
                'capture_sections' => $periodOptions['capture_sections']
                    ?? 'business_overview,traffic_report',
            ];
            foreach (['collector_flow', 'capture_plan', 'profile_sections'] as $key) {
                if (isset($periodOptions[$key]) && trim((string)$periodOptions[$key]) !== '') {
                    $syncOptions[$key] = $periodOptions[$key];
                }
            }
            $result = $service->syncDataSource(
                $this->currentUser,
                (int)$source['id'],
                $syncOptions
            );
            $savedCount += (int)($result['saved_count'] ?? 0);
            $timing = $this->sumAutoFetchTiming($timing, is_array($result['timing'] ?? null) ? $result['timing'] : []);
            $messages[] = '数据源' . (int)$source['id'] . ': ' . (string)($result['message'] ?? $result['status'] ?? '-');
            $this->markCtripProfileStatusFromDataSourceSync($hotelId, $source, $result);
            $syncResults[] = $result;
        }

        $runReadback = $this->selectAutoFetchRunReadback($syncResults);
        $coreReadbackVerified = $this->autoFetchRunReadbackCoreVerified($runReadback);

        return [
            'attempted' => true,
            'success' => $this->autoFetchPlatformRunSucceeded($savedCount, $runReadback),
            'saved_count' => $savedCount,
            'data_period' => $periodOptions['data_period'] ?? 'historical_daily',
            'timing' => $timing,
            'run_readback' => $runReadback,
            'message' => $coreReadbackVerified
                ? "携程 Profile 数据源同步并验证本次任务核心指标回执 {$savedCount} 条"
                : ($savedCount > 0
                    ? '携程 Profile 已写入，但本次任务、入库行、来源追踪或三项核心指标未完整绑定'
                    : '携程 Profile 数据源同步失败：' . implode('；', array_slice($messages, 0, 3))),
        ];
    }

    private function syncMeituanBrowserProfileDataSourcesForAutoFetch(
        int $hotelId,
        string $dataDate,
        bool $interactiveBrowser,
        ?array $sources = null,
        array $periodOptions = []
    ): array {
        $sources = $sources ?? $this->listEnabledBrowserProfileDataSources($hotelId, 'meituan');
        $sources = $this->filterCollectableBrowserProfileDataSources($sources, 'meituan');
        $sources = $this->selectCurrentBrowserProfileDataSources($sources);
        if ($sources === []) {
            return [
                'attempted' => false,
                'success' => false,
                'saved_count' => 0,
                'message' => '',
                'run_readback' => [],
            ];
        }

        $service = new PlatformDataSyncService();
        $savedCount = 0;
        $messages = [];
        $timing = [];
        $syncResults = [];
        foreach ($sources as $source) {
            $result = $service->syncDataSource($this->currentUser, (int)$source['id'], [
                'trigger_type' => 'auto_fetch',
                'data_date' => $dataDate,
                'interactive_browser' => $interactiveBrowser,
                'data_period' => $periodOptions['data_period'] ?? 'historical_daily',
                'snapshot_time' => $periodOptions['snapshot_time'] ?? date('Y-m-d H:i:s'),
                'capture_sections' => 'traffic,orders',
            ]);
            $savedCount += (int)($result['saved_count'] ?? 0);
            $timing = $this->sumAutoFetchTiming($timing, is_array($result['timing'] ?? null) ? $result['timing'] : []);
            $messages[] = '数据源' . (int)$source['id'] . ': ' . (string)($result['message'] ?? $result['status'] ?? '-');
            $syncResults[] = $result;
        }

        $runReadback = $this->selectAutoFetchRunReadback($syncResults);
        $coreReadbackVerified = $this->autoFetchRunReadbackCoreVerified($runReadback);
        return [
            'attempted' => true,
            'success' => $this->autoFetchPlatformRunSucceeded($savedCount, $runReadback),
            'saved_count' => $savedCount,
            'data_period' => $periodOptions['data_period'] ?? 'historical_daily',
            'timing' => $timing,
            'run_readback' => $runReadback,
            'message' => $coreReadbackVerified
                ? "美团 Profile 数据源同步并验证本次任务核心指标回执 {$savedCount} 条"
                : ($savedCount > 0
                    ? '美团 Profile 已写入，但本次任务、入库行、来源追踪或三项核心指标未完整绑定'
                    : '美团 Profile 数据源同步失败：' . implode('；', array_slice($messages, 0, 3))),
        ];
    }

    /** @param array<int, array<string, mixed>> $syncResults */
    private function selectAutoFetchRunReadback(array $syncResults): array
    {
        $selected = [];
        foreach ($syncResults as $result) {
            $receipt = is_array($result['run_readback'] ?? null) ? $result['run_readback'] : [];
            if ($receipt === []) {
                continue;
            }
            if ($selected === [] || (int)($receipt['sync_task_id'] ?? 0) > (int)($selected['sync_task_id'] ?? 0)) {
                $selected = $receipt;
            }
        }
        return $selected;
    }

    private function autoFetchRunReadbackCoreVerified(array $receipt): bool
    {
        $metricKeys = array_values(array_unique(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            is_array($receipt['verified_metric_keys'] ?? null) ? $receipt['verified_metric_keys'] : []
        )));
        return ($receipt['readback_verified'] ?? false) === true
            && strtolower(trim((string)($receipt['p0_status'] ?? ''))) === 'ready'
            && (int)($receipt['sync_task_id'] ?? 0) > 0
            && (int)($receipt['data_source_id'] ?? 0) > 0
            && trim((string)($receipt['started_at'] ?? '')) !== ''
            && array_values(array_filter(
                is_array($receipt['row_ids'] ?? null) ? $receipt['row_ids'] : [],
                static fn($value): bool => (int)$value > 0
            )) !== []
            && array_values(array_filter(
                is_array($receipt['source_trace_ids'] ?? null) ? $receipt['source_trace_ids'] : [],
                static fn($value): bool => trim((string)$value) !== ''
            )) !== []
            && count(array_intersect(['revenue', 'room_nights', 'adr'], $metricKeys)) === 3;
    }

    private function autoFetchPlatformRunSucceeded(int $savedCount, array $receipt): bool
    {
        return $savedCount > 0 && $this->autoFetchRunReadbackCoreVerified($receipt);
    }

    private function selectCurrentBrowserProfileDataSources(array $sources): array
    {
        $sources = array_values(array_filter($sources, static fn($source): bool => is_array($source)));
        return $sources === [] ? [] : [$sources[0]];
    }

    private function markCtripProfileStatusFromDataSourceSync(int $hotelId, array $source, array $result): void
    {
        if (($result['status'] ?? '') !== 'success' || (int)($result['saved_count'] ?? 0) <= 0) {
            return;
        }

        $config = $this->decodeBrowserProfileSourceConfig($source);
        $profileId = $this->ctripProfileStoreIdFromConfig($config, $hotelId);
        if ($profileId === '') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->cachePlatformProfileStatus('ctrip', $hotelId, $profileId, [
            'checked_at' => $now,
            'last_captured_at' => $now,
            'auth_status' => [
                'ok' => true,
                'status' => 'logged_in',
                'message' => 'Ctrip browser Profile data-source sync succeeded.',
            ],
            'capture_gate' => null,
            'status_code' => 'logged_in',
            'data_source_id' => (int)($source['id'] ?? 0),
            'sync_task_id' => (int)($result['task_id'] ?? 0),
        ]);
    }

}
