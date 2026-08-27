<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiEvaluationRunService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Db;

final class AiEvaluationRunReservationMariaDbTest extends TestCase
{
    private string $clientRunKey = '';

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
    }

    protected function setUp(): void
    {
        $explicit = (string)getenv('SUXI_AI_EVALUATION_RESERVATION_DB_TEST') === '1';
        $expectedDatabase = trim((string)getenv('SUXI_E2E_DB_NAME'));
        $databaseRow = Db::query('SELECT DATABASE() AS database_name, VERSION() AS database_version');
        $databaseName = trim((string)($databaseRow[0]['database_name'] ?? ''));
        $dedicated = preg_match('/(?:^|[_-])(?:test(?:ing)?|e2e)(?:$|[_-])/iD', $databaseName) === 1;
        if (!$explicit && (!$dedicated || ($expectedDatabase !== '' && !hash_equals($expectedDatabase, $databaseName)))) {
            self::markTestSkipped('A dedicated MariaDB test database is required for evaluation reservation verification.');
        }
        $columns = Db::query("SHOW COLUMNS FROM `ai_evaluation_runs` WHERE `Field` IN ('claim_token_hash','lease_expires_at')");
        self::assertCount(
            2,
            $columns,
            'The dedicated MariaDB test database must contain every evaluation reservation migration column.'
        );
        $this->clientRunKey = 'eval-mariadb-reservation-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if ($this->clientRunKey !== '') {
            Db::name(AiEvaluationRunService::TABLE)->where('client_run_key', $this->clientRunKey)->delete();
        }
    }

    public function testHeartbeatKeepsClaimActiveAcrossInitialLeaseAndFinalResultReplays(): void
    {
        $firstService = new AiEvaluationRunService();
        $secondService = new AiEvaluationRunService();
        $filters = ['scenario' => 'mariadb', 'prompt_version' => '', 'case_keys' => [], 'limit' => 1];
        $now = new \DateTimeImmutable('2026-08-26 01:00:00', new \DateTimeZone('Asia/Shanghai'));
        $first = $firstService->reserve(
            $this->clientRunKey,
            'reservation_mariadb_v1',
            'local_second_brain',
            $filters,
            true,
            false,
            1,
            $now,
            30
        );
        self::assertSame('claimed', $first['state']);
        self::assertTrue($first['run']['readback_verified']);

        try {
            $secondService->reserve(
                $this->clientRunKey,
                'reservation_mariadb_v1',
                'local_second_brain',
                $filters,
                true,
                false,
                1,
                $now->modify('+1 second'),
                30
            );
            self::fail('The active MariaDB reservation must block a second service instance.');
        } catch (RuntimeException $error) {
            self::assertSame(409, $error->getCode());
        }

        $renewed = $firstService->renewReservation(
            (int)$first['reservation_id'],
            (string)$first['claim_token'],
            $now->modify('+20 seconds'),
            30
        );
        self::assertSame('2026-08-26 01:00:50', $renewed['run']['lease_expires_at']);
        try {
            $secondService->reserve(
                $this->clientRunKey,
                'reservation_mariadb_v1',
                'local_second_brain',
                $filters,
                true,
                false,
                1,
                $now->modify('+31 seconds'),
                30
            );
            self::fail('The MariaDB heartbeat must keep the original claim active past its initial lease.');
        } catch (RuntimeException $error) {
            self::assertSame(409, $error->getCode());
        }

        $result = [
            'dry_run' => true,
            'allow_external_model_call' => false,
            'evaluation_set' => 'reservation_mariadb_v1',
            'model_key' => 'local_second_brain',
            'summary' => ['total' => 1, 'ready' => 1, 'blocked' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0],
            'cases' => [['case_key' => 'mariadb-1', 'status' => 'ready']],
        ];
        $final = $firstService->finalizeReservation(
            (int)$first['reservation_id'],
            (string)$first['claim_token'],
            $result,
            $now->modify('+32 seconds')
        );
        self::assertSame('planned', $final['status']);
        self::assertTrue($final['readback_verified']);
        $replay = $firstService->reserve(
            $this->clientRunKey,
            'reservation_mariadb_v1',
            'local_second_brain',
            $filters,
            true,
            false,
            1,
            $now->modify('+33 seconds'),
            30
        );
        self::assertSame('completed', $replay['state']);
        self::assertSame($final['id'], $replay['run']['id']);
        self::assertSame(1, (int)Db::name(AiEvaluationRunService::TABLE)
            ->where('client_run_key', $this->clientRunKey)
            ->count());
    }
}
