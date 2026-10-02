<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\model\SystemNotification;
use app\service\BrowserCaptureProcessRunner;
use app\service\BrowserProfileCaptureRequestService;
use app\service\CanonicalOtaDailyNaturalAcceptanceService;
use app\service\CtripCollectorWorkflowService;
use app\service\HotelCollectionBindingReceiptService;
use app\service\OtaProfileBindingService;
use app\service\OtaProfileSessionProofService;
use app\service\OtaFailureNotificationService;
use app\service\OnlineDailyDataPersistenceService;
use app\service\OnlineDataAutoFetchStatusStore;
use app\service\PermissionService;
use app\service\PlatformProfileBindingReadinessService;
use app\service\PlatformDataSyncService;
use app\service\ScheduledAutoFetchPolicy;
use app\service\WindowsOtaDispatcherControlService;
use think\Response;
use think\facade\Db;

trait AutoFetchRunModeConcern
{

    private function normalizeAutoFetchMode($value): string
    {
        $mode = strtolower(str_replace(['-', ' '], '_', trim((string)$value)));
        return match ($mode) {
            'cookie', 'cookies', 'cookie_auto', 'cookie_config', 'config', 'api', 'direct_api' => 'cookie_config',
            'profile', 'browser', 'browser_profile', 'profile_browser' => 'profile_browser',
            default => 'hybrid_auto',
        };
    }

    private function platformAutoFetchModeOptionsFromRequest(array $requestData): array
    {
        $options = [];
        foreach ([
            'ctrip_auto_fetch_mode',
            'ctripAutoFetchMode',
            'ctrip_auto_mode',
            'ctripAutoMode',
        ] as $key) {
            if (array_key_exists($key, $requestData) && trim((string)$requestData[$key]) !== '') {
                $options['ctrip_auto_fetch_mode'] = $this->normalizeAutoFetchMode($requestData[$key]);
                break;
            }
        }
        foreach ([
            'meituan_auto_fetch_mode',
            'meituanAutoFetchMode',
            'meituan_auto_mode',
            'meituanAutoMode',
        ] as $key) {
            if (array_key_exists($key, $requestData) && trim((string)$requestData[$key]) !== '') {
                $options['meituan_auto_fetch_mode'] = $this->normalizeAutoFetchMode($requestData[$key]);
                break;
            }
        }

        return $options;
    }

    private function autoFetchModeLabel(string $mode): string
    {
        return match ($this->normalizeAutoFetchMode($mode)) {
            'cookie_config' => 'Cookie/配置自动',
            'profile_browser' => '浏览器 Profile 自动采集',
            default => '接口直连自动',
        };
    }

    private function resolveAutoFetchRunMode(int $hotelId, array $options = []): string
    {
        foreach (['auto_fetch_mode', 'autoMode', 'auto_mode', 'fetch_mode'] as $key) {
            if (array_key_exists($key, $options) && trim((string)$options[$key]) !== '') {
                return $this->normalizeAutoFetchMode($options[$key]);
            }
        }

        $status = cache($this->autoFetchStatusKey($hotelId));
        if (is_array($status)) {
            foreach (['auto_fetch_mode', 'autoMode', 'auto_mode', 'fetch_mode'] as $key) {
                if (array_key_exists($key, $status) && trim((string)$status[$key]) !== '') {
                    return $this->normalizeAutoFetchMode($status[$key]);
                }
            }
        }

        return 'hybrid_auto';
    }

    private function resolvePlatformAutoFetchMode(array $config, array $options, string $platform): string
    {
        foreach ([
            $platform . '_auto_fetch_mode',
            $platform . '_auto_mode',
            'auto_fetch_mode',
            'autoMode',
            'auto_mode',
            'fetch_mode',
        ] as $key) {
            if (array_key_exists($key, $options) && trim((string)$options[$key]) !== '') {
                return $this->normalizeAutoFetchMode($options[$key]);
            }
        }

        foreach (['auto_fetch_mode', 'autoMode', 'auto_mode', 'fetch_mode'] as $key) {
            if (array_key_exists($key, $config) && trim((string)$config[$key]) !== '') {
                return $this->normalizeAutoFetchMode($config[$key]);
            }
        }

        return 'hybrid_auto';
    }

    private function shouldRunCookieConfigTasks(string $mode): bool
    {
        return $this->normalizeAutoFetchMode($mode) !== 'profile_browser';
    }

    private function shouldRunProfileBrowser(string $mode): bool
    {
        return $this->normalizeAutoFetchMode($mode) === 'profile_browser';
    }

    private function shouldRunProfileBrowserForCost(string $mode, int $savedCount): bool
    {
        $mode = $this->normalizeAutoFetchMode($mode);
        if ($mode === 'cookie_config') {
            return false;
        }
        if ($mode === 'profile_browser') {
            return true;
        }

        return false;
    }

    private function shouldRunCtripProfileBrowser(string $mode, array $browserProfileSources): bool
    {
        $mode = $this->normalizeAutoFetchMode($mode);
        if ($mode === 'profile_browser') {
            return true;
        }

        return $mode === 'hybrid_auto' && $browserProfileSources !== [];
    }

    private function shouldRunCtripProfileBrowserForCost(string $mode, int $savedCount, array $browserProfileSources): bool
    {
        if ($this->normalizeAutoFetchMode($mode) === 'profile_browser') {
            return true;
        }

        return $this->shouldRunCtripProfileBrowser($mode, $browserProfileSources);
    }

}
