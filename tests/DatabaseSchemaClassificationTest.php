<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiEvaluationRunService;
use app\service\DatabaseSchemaRequirement;
use app\service\OperationManagementService;
use app\service\WecomAibotService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class DatabaseSchemaClassificationTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$connection = 'schema_classification_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$connection . '.sqlite';
        @unlink(self::$databasePath);
        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$connection;
        $database['connections'][self::$connection] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE schema_present (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        Db::execute(
            "CREATE TABLE schema_generated (validation_status TEXT NOT NULL, "
            . "history_status TEXT GENERATED ALWAYS AS ("
            . "CASE WHEN validation_status = 'verified' THEN 'success' ELSE 'partial' END"
            . ") STORED)"
        );
        Db::execute('CREATE VIEW schema_unreadable AS SELECT * FROM schema_missing_dependency');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    public function testGuardSeparatesPresentMissingAndUnreadableTables(): void
    {
        self::assertSame(
            DatabaseSchemaRequirement::STATUS_PRESENT,
            DatabaseSchemaRequirement::inspectTable('schema_present')['status']
        );
        self::assertSame(
            DatabaseSchemaRequirement::STATUS_MISSING,
            DatabaseSchemaRequirement::inspectTable('schema_absent')['status']
        );
        self::assertSame(
            DatabaseSchemaRequirement::STATUS_UNREADABLE,
            DatabaseSchemaRequirement::inspectTable('schema_unreadable')['status']
        );
    }

    public function testColumnAndOperationProbesDoNotMislabelBrokenViewAsMigrationMissing(): void
    {
        $columns = DatabaseSchemaRequirement::inspectTableColumns('schema_unreadable');
        self::assertSame(DatabaseSchemaRequirement::STATUS_UNREADABLE, $columns['status']);

        try {
            DatabaseSchemaRequirement::assertTableColumns('schema_unreadable', ['id']);
            self::fail('Broken view must not satisfy the schema requirement.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('inspection failed', $exception->getMessage());
            self::assertStringNotContainsString('upgrade required', $exception->getMessage());
        }

        try {
            (new OperationManagementService())->tableExists('schema_unreadable');
            self::fail('Operation schema probe must surface unreadable state.');
        } catch (RuntimeException $exception) {
            self::assertSame(503, $exception->getCode());
            self::assertSame('database_table_probe_failed:schema_unreadable', $exception->getMessage());
            self::assertStringNotContainsString('migration', strtolower($exception->getMessage()));
        }
    }

    public function testSqliteColumnInspectionIncludesGeneratedHistoryStatus(): void
    {
        $inspection = DatabaseSchemaRequirement::inspectTableColumns('schema_generated');

        self::assertSame(DatabaseSchemaRequirement::STATUS_PRESENT, $inspection['status']);
        self::assertSame(
            ['validation_status', 'history_status'],
            $inspection['columns']
        );
        DatabaseSchemaRequirement::assertTableColumns(
            'schema_generated',
            ['validation_status', 'history_status']
        );
    }

    public function testAiEvaluationAndWecomAibotSurfaceUnreadableInsteadOfMissingMigration(): void
    {
        Db::execute('CREATE VIEW ai_evaluation_runs AS SELECT * FROM missing_ai_evaluation_backing');
        try {
            (new AiEvaluationRunService())->read(1);
            self::fail('Broken evaluation view must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('结构检查失败', $exception->getMessage());
            self::assertStringNotContainsString('迁移', $exception->getMessage());
        } finally {
            Db::execute('DROP VIEW ai_evaluation_runs');
        }

        Db::execute('CREATE VIEW wecom_inbound_events AS SELECT * FROM missing_wecom_event_backing');
        try {
            (new WecomAibotService())->ingest([]);
            self::fail('Broken WeCom event view must fail before request validation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('结构检查失败', $exception->getMessage());
            self::assertStringNotContainsString('尚未迁移', $exception->getMessage());
        } finally {
            Db::execute('DROP VIEW wecom_inbound_events');
        }
    }
}
