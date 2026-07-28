<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class OperationsAutomationSchemaMigrationContractTest extends TestCase
{
    private const MIGRATION = '20260728_extend_operations_automation_center_schema.sql';
    private const FOLLOWUP_MIGRATION = '20260728_scope_operations_automation_followup.sql';
    private const SCOPE_HEARTBEAT_MIGRATION = '20260728_t_track_manual_notification_schedule_scopes.sql';

    private static function migration(): string
    {
        $sql = file_get_contents(dirname(__DIR__) . '/database/migrations/' . self::MIGRATION);
        self::assertIsString($sql);

        return $sql;
    }

    private static function followupMigration(): string
    {
        $sql = file_get_contents(dirname(__DIR__) . '/database/migrations/' . self::FOLLOWUP_MIGRATION);
        self::assertIsString($sql);

        return $sql;
    }

    private static function scopeHeartbeatMigration(): string
    {
        $sql = file_get_contents(
            dirname(__DIR__) . '/database/migrations/' . self::SCOPE_HEARTBEAT_MIGRATION
        );
        self::assertIsString($sql);

        return $sql;
    }

    public function testOperatingTargetsGainOnlyNullableIdempotentGoalFields(): void
    {
        $migration = self::migration();

        self::assertMatchesRegularExpression(
            '/ALTER TABLE `operating_target_daily_records`[\s\S]*'
            . 'ADD COLUMN IF NOT EXISTS `target_occupancy_rate_percent` DECIMAL\(7,2\) DEFAULT NULL[\s\S]*'
            . 'ADD COLUMN IF NOT EXISTS `target_revpar` DECIMAL\(12,2\) DEFAULT NULL/i',
            $migration
        );
        self::assertStringNotContainsString('DEFAULT 0', $migration);
    }

    public function testWechatTenantScopeUsesAuthoritativeHotelBackfill(): void
    {
        $migration = self::migration();

        self::assertMatchesRegularExpression(
            '/ALTER TABLE `competitor_wechat_robot`[\s\S]*'
            . 'ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL[\s\S]*'
            . 'idx_competitor_wechat_robot_tenant_scope/i',
            $migration
        );
        self::assertMatchesRegularExpression(
            '/UPDATE `competitor_wechat_robot` AS robot[\s\S]*'
            . 'INNER JOIN `hotels` AS hotel ON hotel\.`id` = robot\.`store_id`[\s\S]*'
            . 'SET robot\.`tenant_id` = hotel\.`tenant_id`/i',
            $migration
        );
        self::assertMatchesRegularExpression(
            '/ALTER TABLE `account_wechat_push_policies`[\s\S]*'
            . 'ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL[\s\S]*'
            . 'idx_account_wechat_push_tenant_scope/i',
            $migration
        );
        self::assertMatchesRegularExpression(
            '/UPDATE `account_wechat_push_policies` AS policy[\s\S]*'
            . 'INNER JOIN `hotels` AS hotel ON hotel\.`id` = policy\.`hotel_id`[\s\S]*'
            . 'SET policy\.`tenant_id` = hotel\.`tenant_id`/i',
            $migration
        );
        self::assertStringNotContainsString('SET robot.`tenant_id` = robot.`store_id`', $migration);
        self::assertStringNotContainsString('SET policy.`tenant_id` = policy.`hotel_id`', $migration);
    }

    public function testScheduleRunLinkExtendsExistingIdempotentScopedLedger(): void
    {
        $root = dirname(__DIR__);
        $migration = self::migration();
        $dispatchBase = file_get_contents(
            $root . '/database/migrations/20260726_create_manual_notification_schedule_dispatches.sql'
        );
        self::assertIsString($dispatchBase);
        self::assertSame(
            1,
            preg_match(
                '/ALTER TABLE `manual_notification_schedule_dispatches`[\s\S]*?;/i',
                $migration,
                $scheduleMigrationMatch
            )
        );
        $scheduleMigration = (string)($scheduleMigrationMatch[0] ?? '');

        self::assertMatchesRegularExpression(
            '/ALTER TABLE `manual_notification_schedule_dispatches`[\s\S]*'
            . 'ADD COLUMN IF NOT EXISTS `schedule_run_id` BIGINT UNSIGNED DEFAULT NULL[\s\S]*'
            . 'ADD INDEX IF NOT EXISTS `idx_manual_notification_dispatch_run` \(`schedule_run_id`\)/i',
            $scheduleMigration
        );
        foreach (['`tenant_id`', '`hotel_id`', '`robot_id`'] as $existingScopeColumn) {
            self::assertStringContainsString($existingScopeColumn, $dispatchBase);
        }
        self::assertStringContainsString(
            'UNIQUE KEY `uniq_manual_notification_schedule_window`',
            $dispatchBase
        );
        self::assertDoesNotMatchRegularExpression(
            '/ADD COLUMN(?: IF NOT EXISTS)? `(tenant_id|hotel_id|robot_id|dispatch_window|delivery_mode)`/i',
            $scheduleMigration
        );
        self::assertStringNotContainsString('ADD UNIQUE', strtoupper($scheduleMigration));
    }

    public function testMigrationIsAdditiveRepeatableAndOutsideOtaSchema(): void
    {
        $migration = self::migration();
        $upper = strtoupper($migration);

        self::assertSame(5, substr_count($migration, 'ADD COLUMN IF NOT EXISTS'));
        self::assertSame(3, substr_count($migration, 'ADD INDEX IF NOT EXISTS'));
        self::assertStringNotContainsString('CREATE TABLE', $upper);
        self::assertStringNotContainsString('DROP TABLE', $upper);
        self::assertStringNotContainsString('DELETE FROM', $upper);
        self::assertStringNotContainsString('MODIFY COLUMN', $upper);
        self::assertDoesNotMatchRegularExpression('/`ota_[^`]+`/i', $migration);
        self::assertStringNotContainsString('dingdandao_pms_integrations', strtolower($migration));
        self::assertStringNotContainsString('dingdandao_pms_push_dispatches', strtolower($migration));
    }

    public function testFollowupScopesSchedulerEvidenceAndCorrectsRevparSemantics(): void
    {
        $migration = self::followupMigration();

        self::assertMatchesRegularExpression(
            '/ALTER TABLE `manual_notification_schedule_runs`[\s\S]*'
            . 'ADD COLUMN IF NOT EXISTS `scope_tenant_id` INT UNSIGNED DEFAULT NULL[\s\S]*'
            . 'idx_manual_notification_schedule_run_tenant_scope[\s\S]*'
            . '`scope_tenant_id`, `scope_hotel_id`, `scope_robot_id`, `observed_at`/i',
            $migration
        );
        self::assertMatchesRegularExpression(
            '/MODIFY COLUMN `target_revpar` DECIMAL\(12,2\) DEFAULT NULL[\s\S]*'
            . 'accommodation-room-fee target RevPAR/i',
            $migration
        );
        self::assertStringNotContainsString('DEFAULT 0', $migration);
        self::assertDoesNotMatchRegularExpression('/`ota_[^`]+`/i', $migration);
    }

    public function testSchedulerHeartbeatKeepsExactPlanScopeWithoutRewritingDispatches(): void
    {
        $migration = self::scopeHeartbeatMigration();

        self::assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS `manual_notification_schedule_run_scopes`[\s\S]*'
            . '`schedule_run_id` BIGINT UNSIGNED NOT NULL[\s\S]*'
            . '`tenant_id` INT UNSIGNED NOT NULL[\s\S]*'
            . '`hotel_id` INT UNSIGNED NOT NULL[\s\S]*'
            . '`robot_id` BIGINT UNSIGNED NOT NULL/i',
            $migration
        );
        self::assertStringContainsString('uq_manual_notification_run_scope', $migration);
        self::assertStringContainsString('idx_manual_notification_scope_heartbeat', $migration);
        self::assertStringNotContainsString('UPDATE `manual_notification_schedule_dispatches`', $migration);
        self::assertStringNotContainsString('DEFAULT 0.00', $migration);
        self::assertDoesNotMatchRegularExpression('/`ota_[^`]+`/i', $migration);
    }
}
