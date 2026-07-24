<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiReportGenerationTaskService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AiReportGenerationTaskContractTest extends TestCase
{
    public function testTaskTransitionsAreMonotonicAndTerminal(): void
    {
        self::assertTrue(AiReportGenerationTaskService::canTransition('queued', 'running'));
        self::assertTrue(AiReportGenerationTaskService::canTransition('queued', 'failed'));
        self::assertTrue(AiReportGenerationTaskService::canTransition('running', 'succeeded'));
        self::assertTrue(AiReportGenerationTaskService::canTransition('running', 'partial'));
        self::assertTrue(AiReportGenerationTaskService::canTransition('running', 'blocked'));
        self::assertFalse(AiReportGenerationTaskService::canTransition('succeeded', 'failed'));
        self::assertFalse(AiReportGenerationTaskService::canTransition('partial', 'running'));
        self::assertFalse(AiReportGenerationTaskService::canTransition('blocked', 'succeeded'));
        self::assertFalse(AiReportGenerationTaskService::canTransition('failed', 'running'));
    }

    public function testTerminalStatusReflectsTheRealModelOutcome(): void
    {
        $llmTask = ['use_llm' => 1];
        self::assertSame('succeeded', AiReportGenerationTaskService::terminalStatusForReport($llmTask, ['model_status' => 'ok']));
        self::assertSame('blocked', AiReportGenerationTaskService::terminalStatusForReport($llmTask, ['model_status' => 'blocked_by_data_quality']));
        self::assertSame('partial', AiReportGenerationTaskService::terminalStatusForReport($llmTask, ['model_status' => 'failed']));
        self::assertSame('partial', AiReportGenerationTaskService::terminalStatusForReport($llmTask, ['model_status' => 'invalid_output']));
        self::assertSame('succeeded', AiReportGenerationTaskService::terminalStatusForReport($llmTask, [
            'model_status' => 'failed',
            'result_readiness' => ['status' => 'available', 'usable' => true],
        ]));
        self::assertSame('succeeded', AiReportGenerationTaskService::terminalStatusForReport(['use_llm' => 0], ['model_status' => 'not_requested']));
        self::assertSame('blocked', AiReportGenerationTaskService::terminalStatusForReport(['use_llm' => 0], ['model_status' => 'blocked_by_data_quality']));
    }

    public function testLeaseExpiryIsDeterministicAndRecoverable(): void
    {
        self::assertTrue(AiReportGenerationTaskService::isLeaseExpired('2026-07-16 09:00:00', '2026-07-16 09:00:01'));
        self::assertFalse(AiReportGenerationTaskService::isLeaseExpired('2026-07-16 09:00:01', '2026-07-16 09:00:00'));
        self::assertFalse(AiReportGenerationTaskService::isLeaseExpired(null, '2026-07-16 09:00:00'));
    }

    public function testActiveDedupeDoesNotDependOnRequestingUserAndDiffersByWorkload(): void
    {
        $firstUserRequest = AiReportGenerationTaskService::activeDedupeKey(7, '2026-07-15', 'model-a', true);
        $secondUserRequest = AiReportGenerationTaskService::activeDedupeKey(7, '2026-07-15', 'model-a', true);
        self::assertSame($firstUserRequest, $secondUserRequest);
        self::assertNotSame($firstUserRequest, AiReportGenerationTaskService::activeDedupeKey(8, '2026-07-15', 'model-a', true));
        self::assertNotSame($firstUserRequest, AiReportGenerationTaskService::activeDedupeKey(7, '2026-07-15', 'model-a', false));
    }

    public function testActiveDedupeIncludesPromptTrustedInputAndSourceRevision(): void
    {
        $base = AiReportGenerationTaskService::activeDedupeKey(
            7,
            '2026-07-15',
            'model-a',
            true,
            'prompt-v1',
            'trusted-v1',
            'revision-a'
        );
        self::assertNotSame($base, AiReportGenerationTaskService::activeDedupeKey(
            7, '2026-07-15', 'model-a', true, 'prompt-v2', 'trusted-v1', 'revision-a'
        ));
        self::assertNotSame($base, AiReportGenerationTaskService::activeDedupeKey(
            7, '2026-07-15', 'model-a', true, 'prompt-v1', 'trusted-v2', 'revision-a'
        ));
        self::assertNotSame($base, AiReportGenerationTaskService::activeDedupeKey(
            7, '2026-07-15', 'model-a', true, 'prompt-v1', 'trusted-v1', 'revision-b'
        ));
    }

    public function testDispatchCapacityNeverExceedsTheConfiguredLimit(): void
    {
        self::assertSame(2, AiReportGenerationTaskService::availableDispatchSlots(2, 0, 0));
        self::assertSame(1, AiReportGenerationTaskService::availableDispatchSlots(2, 1, 0));
        self::assertSame(0, AiReportGenerationTaskService::availableDispatchSlots(2, 1, 1));
        self::assertSame(0, AiReportGenerationTaskService::availableDispatchSlots(2, 4, 0));
        self::assertSame(8, AiReportGenerationTaskService::availableDispatchSlots(99, 0, 0));
    }

    public function testPublicTaskIsWhitelistedAndHotelPermissionIsExplicit(): void
    {
        self::assertTrue(AiReportGenerationTaskService::isHotelPermitted([7, 8], 7));
        self::assertFalse(AiReportGenerationTaskService::isHotelPermitted([7, 8], 9));
        $public = AiReportGenerationTaskService::normalizePublicTask([
            'task_id' => 'airpt_20260716090000_abcdefabcdefabcdefabcdef',
            'tenant_id' => 99,
            'requested_by' => 123,
            'active_dedupe_key' => str_repeat('a', 64),
            'hotel_id' => 7,
            'report_date' => '2026-07-15',
            'status' => 'succeeded',
            'stage' => 'completed',
            'progress_percent' => 100,
            'result_report_id' => 55,
            'cache_hit' => 1,
            'model_status' => 'ok',
            'lease_expires_at' => null,
        ]);
        self::assertTrue($public['done']);
        self::assertSame(55, $public['result_report_id']);
        self::assertSame('ok', $public['model_status']);
        self::assertArrayNotHasKey('tenant_id', $public);
        self::assertArrayNotHasKey('requested_by', $public);
        self::assertArrayNotHasKey('active_dedupe_key', $public);
    }

    public function testPublicFailureMessageDoesNotEchoUpstreamException(): void
    {
        $method = new ReflectionMethod(AiReportGenerationTaskService::class, 'safePublicErrorMessage');
        $method->setAccessible(true);
        $message = $method->invoke(null, 'generation_failed');
        self::assertStringNotContainsString('secret upstream token', $message);
        self::assertSame(
            'AI report generation failed. Retry after checking trusted data and model configuration.',
            $message
        );
    }

    public function testMigrationContainsTrustCacheAndConcurrentTaskContracts(): void
    {
        $sql = (string)file_get_contents(__DIR__ . '/../database/migrations/20260716_create_ai_report_generation_tasks.sql');
        foreach ([
            'readback_verified',
            'readback_verified_at',
            'input_fingerprint',
            'prompt_version',
            'cache_hit_count',
            'ai_report_generation_tasks',
            'active_dedupe_key',
            'lease_expires_at',
            'trusted_input_version',
            'trusted_input_revision',
            'model_status',
            'uk_ai_report_generation_active_dedupe',
            'idx_ai_report_generation_cleanup',
            'ai_report_input_cache',
            'ai_interpretation_json',
            'uk_ai_report_input_cache_fingerprint',
            'hit_count',
            'idx_ai_report_input_cache_cleanup',
        ] as $contract) {
            self::assertStringContainsString($contract, $sql);
        }
    }

    public function testPollingRecoversLeasesAndCleanupIsRegistered(): void
    {
        $serviceSource = (string)file_get_contents(__DIR__ . '/../app/service/AiReportGenerationTaskService.php');
        self::assertMatchesRegularExpression(
            '/function readPublicTask[\s\S]+?recoverExpiredActiveTasks\(null, \$taskId\);[\s\S]+?dispatchQueuedTasks\(\);/',
            $serviceSource
        );
        self::assertStringContainsString("whereIn('status', self::TERMINAL_STATUSES)", $serviceSource);
        self::assertStringContainsString("tableExists('ai_report_input_cache')", $serviceSource);

        $console = (string)file_get_contents(__DIR__ . '/../config/console.php');
        self::assertStringContainsString("'ai-daily-report:cleanup' => 'app\\command\\CleanupAiReportTasks'", $console);
        $config = (string)file_get_contents(__DIR__ . '/../config/llm.php');
        self::assertStringContainsString("'max_concurrent'", $config);
        self::assertStringContainsString("'task_retention_days'", $config);
        self::assertStringContainsString("'cache_retention_days'", $config);
        $patrol = (string)file_get_contents(__DIR__ . '/../scripts/daily_workbench_patrol_cron.php');
        self::assertStringContainsString("['ai-daily-report:cleanup', []]", $patrol);
        self::assertStringContainsString('eligible_expired_active_tasks', $serviceSource);
        self::assertStringContainsString('eligible_log_files', $serviceSource);
    }

    public function testColumnDiscoveryDoesNotUseUnsupportedShowPlaceholderBinding(): void
    {
        $serviceSource = (string)file_get_contents(__DIR__ . '/../app/service/AiReportGenerationTaskService.php');

        self::assertStringContainsString("Db::query('SHOW COLUMNS FROM `' . \$table . '`');", $serviceSource);
        self::assertStringNotContainsString("SHOW COLUMNS FROM `' . \$table . '` LIKE ?", $serviceSource);
        self::assertStringContainsString("\$row['Field'] ?? \$row['field']", $serviceSource);
    }

    public function testAnalysisReferenceAndHumanReviewMigrationIsWiredIntoInitialization(): void
    {
        $sql = (string)file_get_contents(__DIR__ . '/../database/migrations/20260717_create_ai_analysis_review_tables.sql');
        foreach ([
            'analysis_reference_set_versions',
            'version_key',
            'comparability_note',
            'ai_report_human_reviews',
            'subject_type',
            'correction_json',
            'result_version',
        ] as $contract) {
            self::assertStringContainsString($contract, $sql);
        }
        $init = (string)file_get_contents(__DIR__ . '/../database/init_full.sql');
        self::assertStringContainsString('20260717_create_ai_analysis_review_tables.sql', $init);

        $routes = (string)file_get_contents(__DIR__ . '/../route/app.php');
        self::assertStringContainsString("Route::post('/:id/human-judgments', 'AiDailyReport/recordHumanJudgment')", $routes);
    }

    public function testWindowsLauncherUsesUnicodeEncodedPowerShellWithoutBatchFiles(): void
    {
        $root = 'D:\\桌面\\SUXIOS\\宿析OS初始版\\HOTEL';
        $php = 'C:\\xampp\\php\\php.exe';
        $think = $root . '\\think';
        $taskId = 'airpt_20260716123000_abcdefabcdefabcdefabcdef';
        $stdout = $root . '\\runtime\\ai_report_tasks\\' . $taskId . '.stdout.log';
        $stderr = $root . '\\runtime\\ai_report_tasks\\' . $taskId . '.stderr.log';

        $command = AiReportGenerationTaskService::buildWindowsLauncherCommand(
            $php,
            $think,
            $root,
            $taskId,
            $stdout,
            $stderr
        );
        self::assertStringStartsWith('powershell.exe -NoProfile -NonInteractive', $command);
        self::assertStringContainsString('-EncodedCommand ', $command);
        self::assertStringEndsWith(' > NUL 2>&1', $command);
        self::assertStringNotContainsString('.bat', strtolower($command));

        [, $encoded] = explode('-EncodedCommand ', $command, 2);
        $encoded = substr($encoded, 0, -strlen(' > NUL 2>&1'));
        $utf16 = base64_decode(trim($encoded), true);
        self::assertIsString($utf16);
        $script = mb_convert_encoding($utf16, 'UTF-8', 'UTF-16LE');
        foreach ([
            'Start-Process',
            "\$ProgressPreference = 'SilentlyContinue'",
            "-FilePath '" . $php . "'",
            '-ArgumentList $arguments',
            "-WorkingDirectory '" . $root . "'",
            "'" . $think . "'",
            "'ai-daily-report:generate-once'",
            "'--task-id=" . $taskId . "'",
            "-RedirectStandardOutput '" . $stdout . "'",
            "-RedirectStandardError '" . $stderr . "'",
            '-WindowStyle Hidden',
        ] as $contract) {
            self::assertStringContainsString($contract, $script);
        }
        self::assertStringNotContainsString('.bat', strtolower($script));
    }
}
