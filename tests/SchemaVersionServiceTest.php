<?php
declare(strict_types=1);

namespace Tests;

use app\service\SchemaVersionService;
use app\service\SqlSchemaResourceInspector;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaVersionServiceTest extends TestCase
{
    private string $root;
    private PDO $pdo;
    private SchemaVersionService $service;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'suxios_schema_versions_'
            . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/database/migrations', 0777, true);

        file_put_contents(
            $this->root . '/database/init_full.sql',
            "SOURCE ./database/base.sql;\n"
            . "SOURCE ./database/migrations/20260701_create_alpha.sql;\n"
        );
        file_put_contents(
            $this->root . '/database/base.sql',
            "CREATE TABLE baseline_meta (\n"
            . "  id INTEGER PRIMARY KEY,\n"
            . "  label TEXT NOT NULL\n"
            . ");\n"
        );
        file_put_contents(
            $this->root . '/database/migrations/20260701_create_alpha.sql',
            "CREATE TABLE alpha (\n"
            . "  id INTEGER PRIMARY KEY,\n"
            . "  note TEXT NOT NULL,\n"
            . "  score DECIMAL(5,2) DEFAULT 0.00\n"
            . ");\n"
            . "CREATE INDEX idx_alpha_note ON alpha (note);\n"
        );
        file_put_contents(
            $this->root . '/database/migrations/20260702_seed_alpha.sql',
            "-- semicolon in a string must not split the statement\n"
            . "INSERT INTO alpha (id, note) VALUES (1, 'kept;inside');\n"
        );

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->service = new SchemaVersionService($this->pdo, $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testFreshInitializationRegistersBaselineAndPendingMigrations(): void
    {
        $result = $this->service->initializeFreshFromInitFull();

        self::assertSame(1, $result['baseline_registered']);
        self::assertSame(['20260702_seed_alpha.sql'], $result['executed']);
        self::assertTrue($result['status']['ready']);
        self::assertSame(2, $result['status']['applied_count']);
        self::assertSame('kept;inside', $this->pdo->query('SELECT note FROM alpha WHERE id = 1')->fetchColumn());

        $rows = $this->pdo->query(
            'SELECT migration, version, checksum, execution_kind, executed_at FROM schema_versions ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(
            ['20260701_create_alpha.sql', '20260702_seed_alpha.sql'],
            array_column($rows, 'migration')
        );
        self::assertSame(
            ['20260701_create_alpha', '20260702_seed_alpha'],
            array_column($rows, 'version')
        );
        self::assertSame(['executed', 'executed'], array_column($rows, 'execution_kind'));
        foreach (array_column($rows, 'checksum') as $checksum) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string)$checksum);
        }
        foreach (array_column($rows, 'executed_at') as $executedAt) {
            self::assertNotSame('', (string)$executedAt);
        }

        $baselineRows = $this->pdo->query(
            'SELECT source, checksum FROM schema_baseline_sources ORDER BY source'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['database/base.sql'], array_column($baselineRows, 'source'));
        self::assertSame(hash_file('sha256', $this->root . '/database/base.sql'), $baselineRows[0]['checksum']);
    }

    public function testFreshInitializationBaselineAdoptsHistoricalOwnerIdentityGuard(): void
    {
        $migration = '20260723_validate_owner_tenant_bootstrap_targets.sql';
        file_put_contents(
            $this->root . '/database/migrations/' . $migration,
            "THIS HISTORICAL DATA GUARD MUST NOT RUN ON A FRESH DATABASE;\n"
        );

        $result = $this->service->initializeFreshFromInitFull();

        self::assertTrue($result['status']['ready']);
        self::assertNotContains($migration, $result['executed']);
        $statement = $this->pdo->prepare(
            'SELECT execution_kind FROM schema_versions WHERE migration = ?'
        );
        $statement->execute([$migration]);
        self::assertSame('baseline_adopted', $statement->fetchColumn());
    }

    public function testExistingDatabaseRegistersAbsentHistoricalOwnerGuardAsNotApplicable(): void
    {
        $this->service->initializeFreshFromInitFull();
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');
        $migration = '20260723_validate_owner_tenant_bootstrap_targets.sql';
        file_put_contents(
            $this->root . '/database/migrations/' . $migration,
            "THIS HISTORICAL DATA GUARD MUST NOT RUN WHEN ITS TARGETS DO NOT EXIST;\n"
        );

        $result = $this->service->migrate();

        self::assertSame([], $result['executed']);
        self::assertSame([$migration], $result['historical_not_applicable']);
        self::assertTrue($result['status']['ready']);
        $statement = $this->pdo->prepare(
            'SELECT execution_kind FROM schema_versions WHERE migration = ?'
        );
        $statement->execute([$migration]);
        self::assertSame('historical_not_applicable', $statement->fetchColumn());
    }

    public function testHistoricalOwnerGuardStillFailsWhenAnyTargetIdentityExists(): void
    {
        $this->service->initializeFreshFromInitFull();
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');
        $this->pdo->exec("INSERT INTO users (id, username) VALUES (127, 'VIP003')");
        $migration = '20260723_validate_owner_tenant_bootstrap_targets.sql';
        file_put_contents(
            $this->root . '/database/migrations/' . $migration,
            "THIS HISTORICAL DATA GUARD MUST FAIL WHEN A TARGET EXISTS;\n"
        );

        try {
            $this->service->migrate();
            self::fail('Historical guard must not be adopted when a target identity exists.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($migration, $exception->getMessage());
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM schema_versions WHERE migration = ?'
        );
        $statement->execute([$migration]);
        self::assertSame(0, (int)$statement->fetchColumn());
    }

    public function testNewMigrationIsDiscoveredWithoutChangingInitFull(): void
    {
        $this->service->initializeFreshFromInitFull();
        file_put_contents(
            $this->root . '/database/migrations/20260703_add_beta.sql',
            "CREATE TABLE beta (id INTEGER PRIMARY KEY);\n"
        );

        $before = $this->service->status();
        self::assertFalse($before['ready']);
        self::assertSame(['20260703_add_beta.sql'], $before['pending']);

        $result = $this->service->migrate();
        self::assertSame(['20260703_add_beta.sql'], $result['executed']);
        self::assertTrue($result['status']['ready']);
        self::assertSame(3, $result['status']['applied_count']);
    }

    public function testFailedMigrationIsNotRegistered(): void
    {
        $this->service->initializeFreshFromInitFull();
        file_put_contents(
            $this->root . '/database/migrations/20260703_broken.sql',
            "THIS IS NOT VALID SQL;\n"
        );

        try {
            $this->service->migrate();
            self::fail('Broken migration should fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('20260703_broken.sql', $exception->getMessage());
        }

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM schema_versions WHERE migration = ?');
        $statement->execute(['20260703_broken.sql']);
        self::assertSame(0, (int)$statement->fetchColumn());

        $failure = $this->pdo->query(
            "SELECT migration, resolved_at FROM schema_migration_failures WHERE migration = '20260703_broken.sql'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($failure);
        self::assertNull($failure['resolved_at']);
        self::assertSame(['20260703_broken.sql'], $this->service->status()['unresolved_failures']);

        file_put_contents(
            $this->root . '/database/migrations/20260703_broken.sql',
            "CREATE TABLE IF NOT EXISTS recovered (id INTEGER PRIMARY KEY);\n"
        );
        $result = $this->service->migrate();
        self::assertTrue($result['status']['ready']);
        self::assertSame([], $result['status']['unresolved_failures']);
        self::assertNotFalse($this->pdo->query(
            "SELECT resolved_at FROM schema_migration_failures WHERE migration = '20260703_broken.sql'"
        )->fetchColumn());
    }

    public function testFailedMigrationRollsBackOpenTransactionBeforeRecordingFailure(): void
    {
        $this->service->initializeFreshFromInitFull();
        file_put_contents(
            $this->root . '/database/migrations/20260703_transactional_failure.sql',
            "BEGIN TRANSACTION;\n"
            . "CREATE TABLE rollback_probe (id INTEGER PRIMARY KEY);\n"
            . "INSERT INTO rollback_probe (id) VALUES (1);\n"
            . "THIS IS NOT VALID SQL;\n"
        );

        try {
            $this->service->migrate();
            self::fail('Transactional migration failure must be surfaced.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('20260703_transactional_failure.sql', $exception->getMessage());
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(
            0,
            (int)$this->pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'rollback_probe'"
            )->fetchColumn()
        );
        self::assertSame(
            1,
            (int)$this->pdo->query(
                "SELECT COUNT(*) FROM schema_migration_failures "
                . "WHERE migration = '20260703_transactional_failure.sql' AND resolved_at IS NULL"
            )->fetchColumn()
        );
    }

    public function testRegisteredMigrationFailureResolutionIsRetriedAfterCleanupFailure(): void
    {
        $this->service->initializeFreshFromInitFull();
        file_put_contents(
            $this->root . '/database/migrations/20260703_recoverable.sql',
            "THIS IS NOT VALID SQL;\n"
        );

        try {
            $this->service->migrate();
            self::fail('Broken migration should create an unresolved failure record.');
        } catch (RuntimeException) {
            // Expected: the migration itself failed and was not registered.
        }

        file_put_contents(
            $this->root . '/database/migrations/20260703_recoverable.sql',
            "CREATE TABLE recovered_cleanup (id INTEGER PRIMARY KEY);\n"
        );
        $this->pdo->exec(
            "CREATE TRIGGER block_failure_resolution "
            . "BEFORE UPDATE OF resolved_at ON schema_migration_failures "
            . "WHEN NEW.resolved_at IS NOT NULL "
            . "BEGIN SELECT RAISE(ABORT, 'injected resolution failure'); END"
        );

        try {
            $this->service->migrate();
            self::fail('Cleanup failure must keep migration readiness red.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('injected resolution failure', $exception->getMessage());
        }

        self::assertSame(1, (int)$this->pdo->query(
            "SELECT COUNT(*) FROM schema_versions WHERE migration = '20260703_recoverable.sql'"
        )->fetchColumn());
        self::assertSame(['20260703_recoverable.sql'], $this->service->status()['unresolved_failures']);

        $this->pdo->exec('DROP TRIGGER block_failure_resolution');
        $recovered = $this->service->migrate();
        self::assertSame([], $recovered['executed']);
        self::assertTrue($recovered['status']['ready']);
        self::assertSame([], $recovered['status']['unresolved_failures']);
    }

    public function testEmptyDatabaseCannotBeFalselyAdoptedAsLegacyBaseline(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty database cannot adopt');

        $this->service->baselineInitFullSources();
    }

    public function testGovernanceTablesDoNotTurnAnEmptyDatabaseIntoALegacyDatabase(): void
    {
        $this->service->ensureRegistryTable();

        self::assertSame(0, $this->service->applicationTableCount());
        self::assertFalse($this->service->requiresLegacyBaseline());
    }

    public function testLegacyBaselineRejectsColumnTypeAndIndexDriftWithoutRegisteringHistory(): void
    {
        $this->pdo->exec('CREATE TABLE baseline_meta (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE alpha (id TEXT PRIMARY KEY, note TEXT NOT NULL)');

        $report = $this->service->baselineCompatibilityReport();
        self::assertFalse($report['ready']);
        self::assertContains('alpha.id', $report['column_mismatches']);
        self::assertContains('alpha.idx_alpha_note', $report['missing_indexes']);

        try {
            $this->service->baselineInitFullSources();
            self::fail('A structurally incompatible legacy schema must not be baselined.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Legacy baseline preflight failed', $exception->getMessage());
        }
        self::assertFalse($this->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'schema_versions'"
        )->fetchColumn() > 0);
    }

    public function testCompatibleLegacyBaselineRecordsAdoptionKindAndFrozenSourceChecksum(): void
    {
        $this->pdo->exec('CREATE TABLE baseline_meta (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        $this->pdo->exec(
            'CREATE TABLE alpha (id INTEGER PRIMARY KEY, note TEXT NOT NULL, score DECIMAL(5,2) DEFAULT 0)'
        );
        $this->pdo->exec('CREATE INDEX idx_alpha_note ON alpha (note)');

        self::assertSame(1, $this->service->baselineInitFullSources());
        self::assertSame(
            'baseline_adopted',
            $this->pdo->query(
                "SELECT execution_kind FROM schema_versions WHERE migration = '20260701_create_alpha.sql'"
            )->fetchColumn()
        );
        self::assertSame(
            hash_file('sha256', $this->root . '/database/base.sql'),
            $this->pdo->query(
                "SELECT checksum FROM schema_baseline_sources WHERE source = 'database/base.sql'"
            )->fetchColumn()
        );

        self::assertTrue($this->service->migrate()['status']['ready']);
    }

    public function testCreateParserHandlesMultilineClausesAndNamedIndexesWithoutFakeColumns(): void
    {
        file_put_contents(
            $this->root . '/database/parser_fixture.sql',
            "CREATE TABLE parser_fixture (\n"
            . "  id INTEGER NOT NULL,\n"
            . "  content TEXT NOT NULL,\n"
            . "  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP\n"
            . "    ON UPDATE CURRENT_TIMESTAMP,\n"
            . "  UNIQUE KEY uk_parser_id (id),\n"
            . "  FULLTEXT INDEX idx_parser_content (content)\n"
            . ");\n"
            . "ALTER TABLE parser_fixture\n"
            . "  ADD COLUMN IF NOT EXISTS id BIGINT DEFAULT NULL,\n"
            . "  MODIFY COLUMN content LONGTEXT NOT NULL COMMENT 'wide; encrypted';\n"
        );

        $resources = SqlSchemaResourceInspector::parse($this->root, ['database/parser_fixture.sql']);
        self::assertSame(['id', 'content', 'updated_at'], $resources['schema']['parser_fixture']);
        self::assertArrayHasKey('uk_parser_id', $resources['indexes']['parser_fixture']);
        self::assertArrayHasKey('idx_parser_content', $resources['indexes']['parser_fixture']);
        self::assertArrayNotHasKey('ON', $resources['columns']['parser_fixture']);
        self::assertArrayNotHasKey('FULLTEXT', $resources['columns']['parser_fixture']);
        self::assertStringContainsString('INTEGER NOT NULL', $resources['columns']['parser_fixture']['id']);
        self::assertStringContainsString('LONGTEXT NOT NULL', $resources['columns']['parser_fixture']['content']);
    }

    public function testAppliedMigrationAndFrozenSourceChecksumDriftBlockReadyState(): void
    {
        $this->service->initializeFreshFromInitFull();
        file_put_contents(
            $this->root . '/database/migrations/20260701_create_alpha.sql',
            (string)file_get_contents($this->root . '/database/migrations/20260701_create_alpha.sql')
            . "-- changed after application\n"
        );
        file_put_contents(
            $this->root . '/database/base.sql',
            (string)file_get_contents($this->root . '/database/base.sql')
            . "-- changed frozen source\n"
        );

        $status = $this->service->status();
        self::assertFalse($status['ready']);
        self::assertSame(['20260701_create_alpha.sql'], $status['checksum_mismatches']);
        self::assertSame(['database/base.sql'], $status['baseline_checksum_mismatches']);
    }

    public function testKnownIdempotencyGuardRevisionsPreserveExactRegisteredChecksums(): void
    {
        $this->service->initializeFreshFromInitFull();
        $projectRoot = realpath(__DIR__ . '/..');
        self::assertIsString($projectRoot);
        $revisions = [
            '20260725_add_account_wechat_notification_binding.sql' => [
                '1d257f3d35e9c8edfe020e1ecc665a583ace97232a8218c1f081ab5c1097ee0f',
                'df3165a834beac6b067ee134199314cca722c3e1dead9407c82fe500f07b91e6',
            ],
            '20260726_extend_manual_notification_dispatch_attempts.sql' => [
                '43875cfe53bff7f97cd68a787232a121c0c620e39795449fe23f7a9c45ff5b19',
                '6440cc5549990223d4857bfc1c80b81ab8b096f490be60e33405c319c0a876d7',
            ],
            '20260726_extend_manual_notification_schedule_runs_scope_robot.sql' => [
                '0a579e36892ff2585a658cb6877ffb6c20dfbeb3474033a78398642c6acfc4e2',
                '774a2e32a1bb61ce7cef9f1a15591374fc0ce50febd23897e965bcbf10379fb9',
            ],
            '20260730_zzzzz_harden_manual_notification_dispatch_claims.sql' => [
                '9de6848d905344e105d7d0b3e44a41fd89b0af2c633c78769dc9610196491167',
                '3e588a9fc391f065976cf9a99a9db0c4ebe618014d2cf491df3c3c012059cce0',
            ],
            '20260803_enforce_single_active_temporal_forecast_trial.sql' => [
                '867b1adbbe762b04101dfbe6ef91f6c613fd9ed2149ba8846ebb1780f0bfc35e',
                '578e9f96fa438e8ff2ba2539dca34322889ec2e16933a7ebe3087d4f1709cd70',
            ],
            '20260809_b_absorb_ctrip_commission_reform_watch.sql' => [
                'e7634290b67bd1c42c0ea4daed10efa2943df806395faa9f1f525da4a97300f7',
                'b0b3786d1f1074ca4b5ec7329fb359c9d1571f5f138198bea7b4c748d86479ad',
            ],
            '20260809_c_repair_ctrip_reform_watch_retrieval_traceability.sql' => [
                'd3c3ad5b87199fdba481b9fd6fbe2b3d4f20ce9033cd72673eeeb966019ec18d',
                '60aae2f7cd0292b45a7d740edd3886a5d78f1c96d2c5c5e8f23ceaf0167935ad',
            ],
            '20260809_d_repair_ctrip_reform_watch_checklist_evidence_label.sql' => [
                'd89bc7633624351221cb2cf6786a042758afd9266864c62181c8478acd45494e',
                '1e044674a15b745c94dbadb4b23f8065897ce1a1b03891235ffea3297e3f709a',
            ],
        ];
        $insert = $this->pdo->prepare(
            'INSERT INTO schema_versions '
            . '(migration, version, checksum, execution_kind, executed_at) '
            . 'VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        foreach ($revisions as $migration => [$registeredChecksum, $requiredChecksum]) {
            $source = $projectRoot . '/database/migrations/' . $migration;
            $target = $this->root . '/database/migrations/' . $migration;
            self::assertSame($requiredChecksum, hash_file('sha256', $source));
            self::assertNotFalse(file_put_contents($target, (string)file_get_contents($source)));
            $insert->execute([
                $migration,
                substr($migration, 0, -4),
                $registeredChecksum,
                'executed',
            ]);
        }

        $before = $this->service->status();
        self::assertTrue($before['ready']);
        self::assertSame([], $before['checksum_mismatches']);

        $result = $this->service->migrate();
        self::assertSame([], $result['checksum_upgrades']);
        self::assertTrue($result['status']['ready']);
        $select = $this->pdo->prepare('SELECT checksum FROM schema_versions WHERE migration = ?');
        foreach ($revisions as $migration => [$registeredChecksum]) {
            $select->execute([$migration]);
            self::assertSame($registeredChecksum, $select->fetchColumn());
        }
    }

    public function testExistingChecksumColumnNeverSilentlyBackfillsMissingEvidence(): void
    {
        $this->service->initializeFreshFromInitFull();
        $this->pdo->exec(
            "UPDATE schema_versions SET checksum = NULL "
            . "WHERE migration = '20260701_create_alpha.sql'"
        );

        try {
            $this->service->migrate();
            self::fail('Missing applied-migration checksum evidence must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'automatic backfill is refused: 20260701_create_alpha.sql',
                $exception->getMessage()
            );
        }

        self::assertNull($this->pdo->query(
            "SELECT checksum FROM schema_versions "
            . "WHERE migration = '20260701_create_alpha.sql'"
        )->fetchColumn());
    }

    public function testSingleActiveTrialMigrationUsesRepeatableMariaDbGuards(): void
    {
        $migration = (string)file_get_contents(
            __DIR__ . '/../database/migrations/20260803_enforce_single_active_temporal_forecast_trial.sql'
        );

        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `active_slot`', $migration);
        self::assertStringContainsString(
            'ADD UNIQUE INDEX IF NOT EXISTS `uniq_temporal_forecast_trial_active`',
            $migration
        );
    }

    public function testUnknownRegistrationKeepsDatabaseOutOfReadyState(): void
    {
        $this->service->initializeFreshFromInitFull();
        $this->pdo->exec(
            "INSERT INTO schema_versions (migration, version, executed_at) VALUES ('20990101_unknown.sql', '20990101_unknown', CURRENT_TIMESTAMP)"
        );

        $status = $this->service->status();
        self::assertFalse($status['ready']);
        self::assertSame(['20990101_unknown.sql'], $status['unknown_registrations']);
    }

    public function testSqlSplitterSupportsStoredProcedureDelimitersWithoutSplittingItsBody(): void
    {
        $sql = <<<'SQL'
DELIMITER $$
CREATE PROCEDURE repair_fixture()
BEGIN
    SET @message = 'keep;inside';
    SELECT 1;
END$$
CALL repair_fixture()$$
DROP PROCEDURE repair_fixture$$
DELIMITER ;
SQL;

        $statements = SchemaVersionService::splitSqlStatements($sql);

        self::assertCount(3, $statements);
        self::assertStringStartsWith('CREATE PROCEDURE repair_fixture()', $statements[0]);
        self::assertStringContainsString("SET @message = 'keep;inside';", $statements[0]);
        self::assertStringContainsString('SELECT 1;', $statements[0]);
        self::assertSame('CALL repair_fixture()', $statements[1]);
        self::assertSame('DROP PROCEDURE repair_fixture', $statements[2]);
    }

    public function testProjectCatalogIncludesTenantAndVersionGovernanceMigrations(): void
    {
        $projectRoot = realpath(__DIR__ . '/..');
        self::assertIsString($projectRoot);
        $catalog = SchemaVersionService::migrationCatalog($projectRoot);
        $names = array_column($catalog, 'migration');

        self::assertContains('20260722_add_hotels_city.sql', $names);
        self::assertContains('20260722_create_schema_versions.sql', $names);
        self::assertContains('20260722_create_tenants_and_decouple_hotel_scope.sql', $names);
        self::assertContains('20260722_pre_repair_batched_tenant_history_scope.sql', $names);
        self::assertContains('20260722_repair_remaining_tenant_history_scope.sql', $names);
        self::assertContains('20260722_harden_schema_version_governance.sql', $names);
        self::assertContains('20260722_track_frozen_baseline_sources.sql', $names);
        self::assertContains('20260728_u_grant_role2_wechat_notification_access.sql', $names);
        self::assertContains('20260728_v_restrict_role2_wechat_notification_access.sql', $names);
        self::assertLessThan(
            array_search('20260728_v_restrict_role2_wechat_notification_access.sql', $names, true),
            array_search('20260728_u_grant_role2_wechat_notification_access.sql', $names, true)
        );
        self::assertLessThan(
            array_search('20260722_repair_remaining_tenant_history_scope.sql', $names, true),
            array_search('20260722_pre_repair_batched_tenant_history_scope.sql', $names, true)
        );
        self::assertSame($names, array_values(array_unique($names)));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
