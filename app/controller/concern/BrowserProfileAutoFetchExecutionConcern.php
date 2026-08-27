<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\BrowserCaptureProcessRunner;
use app\service\BrowserProfileCaptureRequestService;

trait BrowserProfileAutoFetchExecutionConcern
{
    /**
     * @param list<string> $args
     * @param list<string> $ephemeralArtifacts
     * @return array{lock_acquired:bool,run_result:array<string,mixed>,lock_released:bool}
     */
    private function runLockedBrowserProfileAutoFetch(
        string $platform,
        string $profileKey,
        array $args,
        string $projectRoot,
        int $timeoutSeconds,
        array $ephemeralArtifacts = []
    ): array {
        $lock = BrowserProfileCaptureRequestService::acquireProfileCaptureLock(
            $projectRoot,
            $platform,
            $profileKey
        );
        if (!is_resource($lock)) {
            return [
                'lock_acquired' => false,
                'run_result' => [
                    'success' => false,
                    'status_code' => 'resource_busy_login',
                    'message' => 'Browser Profile capture is already running for this profile.',
                    'process_started' => false,
                ],
                'lock_released' => false,
            ];
        }

        $runResult = ['process_started' => false];
        $lockReleased = false;
        try {
            $runResult = (array)$this->runMeituanCaptureProcess($args, $projectRoot, $timeoutSeconds);
        } catch (\Throwable $e) {
            $runResult = [
                'success' => false,
                'status_code' => 'process_runner_failed',
                'message' => 'Browser Profile capture runner failed.',
                'stdout' => '',
                'stderr' => '',
                'exit_code' => -1,
                'process_started' => true,
                'process_pid' => 0,
                'process_tree_exit_confirmed' => false,
                'process_tree' => ['supported' => false, 'platform' => PHP_OS_FAMILY, 'tracked_members' => [], 'survivors' => []],
                'termination' => [
                    'contract' => BrowserCaptureProcessRunner::TERMINATION_CONTRACT,
                    'reason' => 'runner_exception',
                    'platform' => PHP_OS_FAMILY,
                    'confirmed_exited' => false,
                    'confirmation_source' => 'unconfirmed',
                    'errors' => ['runner_exception'],
                ],
            ];
        } finally {
            $runResult = BrowserProfileCaptureRequestService::settleEphemeralCaptureArtifacts(
                $runResult,
                $ephemeralArtifacts
            );
            $lockReleased = BrowserProfileCaptureRequestService::finalizeProfileCaptureLock($lock, $runResult);
        }

        return [
            'lock_acquired' => true,
            'run_result' => $runResult,
            'lock_released' => $lockReleased,
        ];
    }
}
