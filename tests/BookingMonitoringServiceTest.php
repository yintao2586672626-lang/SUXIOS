<?php
declare(strict_types=1);

use app\service\BookingDemandPlanningService;
use app\service\BookingMonitoringService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class BookingMonitoringServiceTest extends TestCase
{
    private static array $originalConfig;
    private static string $sqlitePath;

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'booking_monitor_test_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);
        $config = self::$originalConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$sqlitePath, 'prefix' => '', 'fields_strict' => false];
        Config::set($config, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL, status INTEGER NOT NULL DEFAULT 1)');
        Db::execute('CREATE TABLE room_types (id INTEGER PRIMARY KEY, hotel_id INTEGER NOT NULL, name TEXT NOT NULL, is_enabled INTEGER NOT NULL DEFAULT 1)');
        $shared = 'id INTEGER PRIMARY KEY AUTOINCREMENT, contract_version TEXT NOT NULL, tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL, source_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, fact_scope TEXT NOT NULL,
            stay_date TEXT NOT NULL, captured_at TEXT NOT NULL, source_method TEXT NOT NULL, source_ref_hash TEXT NOT NULL,
            on_books_room_nights REAL NULL, on_books_room_revenue REAL NULL, cumulative_cancel_room_nights REAL NULL,
            gross_booking_room_nights REAL NULL, quality_status TEXT NOT NULL, readback_verified INTEGER NOT NULL,
            idempotency_key TEXT NOT NULL, content_digest TEXT NOT NULL, created_by INTEGER NOT NULL, created_at TEXT NOT NULL';
        Db::execute('CREATE TABLE hotel_on_books_snapshots (' . $shared . ', UNIQUE(tenant_id,hotel_id,platform,stay_date,idempotency_key))');
        Db::execute('CREATE TABLE hotel_room_type_on_books_snapshots (' . $shared . ', room_type_id INTEGER NOT NULL,
            room_type_name TEXT NOT NULL, supersedes_snapshot_id INTEGER NULL, UNIQUE(tenant_id,hotel_id,idempotency_key))');
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        Db::execute('DELETE FROM hotel_room_type_on_books_snapshots');
        Db::execute('DELETE FROM hotel_on_books_snapshots');
        Db::execute('DELETE FROM room_types');
        Db::execute('DELETE FROM hotels');
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 7, 'name' => 'TEST-ONLY酒店80'],
            ['id' => 82, 'tenant_id' => 7, 'name' => 'TEST-ONLY酒店82'],
            ['id' => 81, 'tenant_id' => 8, 'name' => 'TEST-ONLY其他租户'],
        ]);
        Db::name('room_types')->insertAll([
            ['id' => 1, 'hotel_id' => 80, 'name' => 'TEST-ONLY大床'],
            ['id' => 2, 'hotel_id' => 80, 'name' => 'TEST-ONLY双床'],
            ['id' => 3, 'hotel_id' => 82, 'name' => 'TEST-ONLY大床'],
        ]);
    }

    public function testImportExactReadbackReplayAndManualQualityCannotSelfPromote(): void
    {
        $service = $this->service();
        $row = $this->row('2026-10-02 09:00:00', 10);
        $row['quality_status'] = 'verified';
        $row['source_method'] = 'authorized_api_export';
        $saved = $service->saveSnapshots(7, [80], [$row], 9);
        self::assertSame('saved_readback_verified', $saved['save_status']);
        self::assertTrue($saved['readback_verified']);
        self::assertSame('manual_confirmed', $saved['snapshots'][0]['quality_status']);
        self::assertSame('manual_file_import', $saved['snapshots'][0]['source_method']);
        self::assertSame('TEST-ONLY大床', $saved['snapshots'][0]['room_type_name']);
        self::assertSame($saved['snapshots'][0], $service->readSnapshot(7, [80], 80, $saved['snapshots'][0]['id']) + ['idempotent' => false]);
        $replay = $service->saveSnapshots(7, [80], [$row], 9);
        self::assertTrue($replay['snapshots'][0]['idempotent']);
        self::assertSame($saved['snapshots'][0]['id'], $replay['snapshots'][0]['id']);
        self::assertSame(1, Db::name(BookingMonitoringService::TABLE)->count());
    }

    public function testConcurrentBatchWinnerIsVerifiedOutsideFailedTransaction(): void
    {
        $row = $this->row('2026-10-02 09:00:00', 10);
        $row['idempotency_key'] = 'TEST-ONLY-concurrent-winner';
        $winner = $this->service()->saveSnapshots(7, [80], [$row], 9);
        $attempts = 0;
        $service = $this->service(transactionRunner: static function (callable $callback) use (&$attempts): array {
            $attempts++;
            throw new RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        });
        $replayed = $service->saveSnapshots(7, [80], [$row], 9);
        self::assertSame(1, $attempts);
        self::assertSame($winner['snapshots'][0]['id'], $replayed['snapshots'][0]['id']);
        self::assertSame($winner['snapshots'][0]['content_digest'], $replayed['snapshots'][0]['content_digest']);
        self::assertTrue($replayed['snapshots'][0]['idempotent']);
        self::assertSame(1, Db::name(BookingMonitoringService::TABLE)->count());
    }

    public function testDeadlockWithPartialWinnerRetriesWholeBatchWithoutDuplicateOrPartialReceipt(): void
    {
        $first = $this->row('2026-10-01 09:00:00', 8);
        $second = $this->row('2026-10-02 09:00:00', 10);
        $winner = $this->service()->saveSnapshots(7, [80], [$first], 9)['snapshots'][0];
        $attempts = 0;
        $service = $this->service(transactionRunner: static function (callable $callback) use (&$attempts): array {
            if (++$attempts === 1) throw new RuntimeException('Deadlock found when trying to get lock', 1213);
            return Db::transaction($callback);
        });
        $saved = $service->saveSnapshots(7, [80], [$first, $second], 9);
        self::assertSame(2, $attempts);
        self::assertSame(2, $saved['row_count']);
        self::assertSame($winner['id'], $saved['snapshots'][0]['id']);
        self::assertTrue($saved['snapshots'][0]['idempotent']);
        self::assertFalse($saved['snapshots'][1]['idempotent']);
        self::assertSame(2, Db::name(BookingMonitoringService::TABLE)->count());
    }

    public function testConcurrentWinnerWithDifferentOrCorruptedContentNeverClaimsReadbackSuccess(): void
    {
        foreach (['different_submission', 'corrupted_winner'] as $case) {
            $row = $this->row('2026-10-02 09:00:00', 10);
            $row['idempotency_key'] = 'TEST-ONLY-' . $case;
            $winner = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
            if ($case === 'different_submission') $row['on_books_room_nights'] = 11;
            else Db::name(BookingMonitoringService::TABLE)->where('id', $winner['id'])->update(['on_books_room_nights' => 99]);
            $service = $this->service(transactionRunner: static function (callable $callback): array {
                throw new RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
            });
            try {
                $service->saveSnapshots(7, [80], [$row], 9);
                self::fail('unverified concurrent winner cannot succeed');
            } catch (RuntimeException $error) {
                self::assertSame($case === 'different_submission' ? 'booking_monitor_idempotency_conflict' : 'booking_monitor_content_digest_mismatch', $error->getMessage());
            }
        }
        self::assertSame(2, Db::name(BookingMonitoringService::TABLE)->count());
    }

    public function testWriteRecoveryBudgetAndOrdinaryFailureStayExplicitWithNoPartialSave(): void
    {
        foreach ([['Deadlock found when trying to get lock', 1213, 3], ['TEST-ONLY ordinary write failure', 0, 1]] as [$message, $code, $expectedAttempts]) {
            $attempts = 0;
            $service = $this->service(transactionRunner: static function (callable $callback) use (&$attempts, $message, $code): array {
                $attempts++;
                throw new RuntimeException($message, $code);
            });
            try {
                $service->saveSnapshots(7, [80], [$this->row('2026-10-02 09:00:00', 10)], 9);
                self::fail('exhausted or ordinary failure must remain failure');
            } catch (RuntimeException $error) {
                self::assertSame($message, $error->getMessage());
            }
            self::assertSame($expectedAttempts, $attempts);
            self::assertSame(0, Db::name(BookingMonitoringService::TABLE)->count());
        }
    }

    public function testNewFourDecimalLegacySnapshotsRemainExactAndUnsplitInMonitor(): void
    {
        $planning = new BookingDemandPlanningService(static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-02 12:00:00', new DateTimeZone('Asia/Shanghai')));
        foreach (['2026-10-01 09:00:00' => 10.1233, '2026-10-02 09:00:00' => 10.1234] as $capture => $rooms) {
            $row = $this->row($capture, $rooms);
            $row['source_method'] = 'manual_file_import';
            $row['quality_status'] = 'manual_confirmed';
            $row['idempotency_key'] = 'TEST-ONLY-legacy-precision-' . $capture;
            $row['on_books_room_revenue'] = 1000.1234;
            $row['cumulative_cancel_room_nights'] = 0.0001;
            $row['gross_booking_room_nights'] = 12.1234;
            $saved = $planning->saveOnBooksSnapshot(7, [80], 80, $row, 9);
            self::assertSame($rooms, $saved['on_books_room_nights']);
            self::assertSame(1000.1234, $saved['on_books_room_revenue']);
            self::assertSame(0.0001, $saved['cumulative_cancel_room_nights']);
            self::assertSame(12.1234, $saved['gross_booking_room_nights']);
            self::assertSame($saved, $planning->readSnapshot(7, 80, $saved['id']) + ['idempotent' => false]);
        }
        $view = $this->service()->overview(7, [80], [80], $this->query());
        self::assertSame(0.0001, $this->cell($view, 80, 0)['net_pickup_24h_room_nights']);
        self::assertSame(10.1234, $this->cell($view, 80, 0)['current']['on_books_room_nights']);
        self::assertSame('missing', $this->cell($view, 80, 1)['current']['status']);
    }

    public function testLegacyHighRevenueSummaryRemainsReadableInMonitorWithoutRelaxingWrites(): void
    {
        $planning = new BookingDemandPlanningService(static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-02 12:00:00', new DateTimeZone('Asia/Shanghai')));
        $digests = [];
        foreach (['2026-10-01 09:00:00' => '9999999999.00', '2026-10-02 09:00:00' => '10000000000.00'] as $capture => $revenue) {
            $input = array_replace($this->row($capture, 10), [
                'source_method' => 'manual_file_import', 'quality_status' => 'manual_confirmed',
            ]);
            $content = $planning->validatedSnapshotContent(7, [80], 80, $input);
            $content['on_books_room_revenue'] = (float)$revenue;
            $digestContent = $content;
            unset($digestContent['hotel_id']);
            ksort($digestContent);
            $digest = hash('sha256', json_encode($digestContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            $stored = array_replace($content, ['on_books_room_revenue' => $revenue]) + [
                'content_digest' => $digest, 'idempotency_key' => 'TEST-ONLY-legacy-high-' . $capture,
                'created_by' => 9, 'created_at' => $capture,
            ];
            // Seed an old decimal as a bound string; the current writer must still reject new high amounts.
            $pdo = Db::connect()->getPdo();
            $statement = $pdo->prepare('INSERT INTO ' . BookingDemandPlanningService::SNAPSHOT_TABLE . ' (' . implode(',', array_keys($stored)) . ') VALUES (' . implode(',', array_fill(0, count($stored), '?')) . ')');
            $statement->execute(array_values($stored));
            $id = (int)$pdo->lastInsertId();
            $read = $planning->readSnapshot(7, 80, $id);
            self::assertSame((float)$revenue, $read['on_books_room_revenue']);
            self::assertSame($digest, $read['content_digest']);
            $digests[$id] = $digest;
        }
        $cell = $this->cell($this->service()->overview(7, [80], [80], $this->query()), 80, 0);
        self::assertSame('ready', $cell['status']);
        self::assertSame(10000000000.0, $cell['current']['on_books_room_revenue']);
        self::assertSame(9999999999.0, $cell['baseline']['on_books_room_revenue']);
        self::assertSame(1.0, $cell['room_revenue_delta_24h']);
        foreach ($digests as $id => $digest) self::assertSame($digest, Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->where('id', $id)->value('content_digest'));
        try {
            $this->service()->saveSnapshots(7, [80], [array_replace($this->row('2026-10-02 10:00:00', 10, 0), ['on_books_room_revenue' => '10000000000.00'])], 9);
            self::fail('Legacy read compatibility must not relax the new monitoring writer limit');
        } catch (InvalidArgumentException $error) {
            self::assertSame('on_books_room_revenue_out_of_range', $error->getMessage());
        }
        self::assertSame(0, Db::name(BookingMonitoringService::TABLE)->count());
        self::assertSame(2, Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->count());
    }

    public function testDisabledRoomRejectsNewFactsButKeepsHistoricalCorrectionAndDimension(): void
    {
        $service = $this->service();
        $row = $this->row('2026-10-02 09:00:00', 10);
        $original = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        Db::name('room_types')->where('id', 1)->update(['is_enabled' => 0]);
        Db::name('room_types')->where('id', 2)->update(['is_enabled' => 0]);
        try {
            $service->saveSnapshots(7, [80], [$this->row('2026-10-02 10:00:00', 12)], 9);
            self::fail('disabled room cannot accept new facts');
        } catch (RuntimeException $error) {
            self::assertSame('booking_monitor_room_type_outside_hotel', $error->getMessage());
        }
        $corrected = $service->saveSnapshots(7, [80], [array_replace($row, [
            'supersedes_snapshot_id' => $original['id'], 'on_books_room_nights' => 11,
        ])], 9)['snapshots'][0];
        $view = $service->overview(7, [80], [80], $this->query());
        self::assertSame([], $view['room_types']);
        self::assertSame('TEST-ONLY大床（历史房型）', $this->cell($view, 80, 1)['room_type_name']);
        self::assertSame(11.0, $this->cell($view, 80, 1)['current']['on_books_room_nights']);
        self::assertNotContains(2, array_column($view['cells'], 'room_type_id'));
        self::assertSame($original['room_type_name'], $corrected['room_type_name']);
        self::assertSame($original, $service->readSnapshot(7, [80], 80, $original['id']) + ['idempotent' => false]);
    }

    public function testManualEntryAndFileImportKeepDistinctSourcesAndLegacyDefaults(): void
    {
        $service = $this->service();
        $manual = $this->row('2026-10-02 09:00:00', 10) + ['source_method' => 'manual_entry'];
        $saved = $service->saveSnapshots(7, [80], [$manual], 9)['snapshots'][0];
        self::assertSame('manual_entry', $saved['source_method']);
        self::assertSame($saved, $service->readSnapshot(7, [80], 80, $saved['id']) + ['idempotent' => false]);
        $legacy = $service->saveSnapshots(7, [80], [$this->row('2026-10-02 10:00:00', 12)], 9)['snapshots'][0];
        self::assertSame('manual_file_import', $legacy['source_method']);
    }

    public function testExcessMetricPrecisionRejectsEntireBatchInsteadOfConfirmingZero(): void
    {
        foreach (['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights'] as $field) {
            foreach ([0.00001, '0.00001', '1e-5', '10.12345'] as $value) {
                try {
                    $this->service()->saveSnapshots(7, [80], [$this->row('2026-10-01 09:00:00', 8),
                        array_replace($this->row('2026-10-02 09:00:00', 10), [$field => $value])], 9);
                    self::fail('overprecision cannot become a stored rounded fact');
                } catch (InvalidArgumentException $error) {
                    self::assertSame($field . '_precision_invalid', $error->getMessage());
                }
                self::assertSame(0, Db::name(BookingMonitoringService::TABLE)->count());
            }
        }
    }

    public function testManualMetricsAtCompatibleMaximumSaveReadAndReplayExactly(): void
    {
        $row = $this->row('2026-10-02 09:00:00', 1);
        foreach (['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights'] as $field) {
            $row[$field] = '9999999999.9999';
        }
        $saved = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        foreach (['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights'] as $field) {
            self::assertSame(9999999999.9999, $saved[$field]);
        }
        self::assertSame($saved, $this->service()->readSnapshot(7, [80], 80, $saved['id']) + ['idempotent' => false]);
        $replayed = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertSame($saved['id'], $replayed['id']);
        self::assertSame($saved['content_digest'], $replayed['content_digest']);
        self::assertTrue($replayed['idempotent']);
    }

    public function testSignedZeroAllMetricsSaveAndReadBackAsPositiveZeroForHotelAndRoom(): void
    {
        foreach ([0, 1] as $roomId) {
            foreach (['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights'] as $field) {
                foreach ([-0.0, '-0.0000'] as $index => $value) {
                    $row = array_replace($this->row('2026-10-02 09:00:00', 1, $roomId), [$field => $value,
                        'idempotency_key' => 'TEST-ONLY-zero-' . $roomId . '-' . $field . '-' . $index]);
                    $saved = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
                    self::assertSame('0.0', json_encode($saved[$field], JSON_PRESERVE_ZERO_FRACTION));
                    self::assertSame($saved, $this->service()->readSnapshot(7, [80], 80, $saved['id']) + ['idempotent' => false]);
                    self::assertSame($saved['id'], $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0]['id']);
                }
            }
        }
    }

    public static function invalidSnapshotIdentityTypes(): array
    {
        $cases=[];
        foreach (['hotel_id','room_type_id','supersedes_snapshot_id'] as $field) {
            foreach (['true'=>true,'false'=>false,'integer float'=>1.0,'zero float'=>0.0,'empty array'=>[],
                'array identity'=>[1],'fractional string'=>'1.0'] as $name=>$value) $cases[$field.' '.$name]=[$field,$value];
        }
        return $cases;
    }

    #[DataProvider('invalidSnapshotIdentityTypes')]
    public function testImportRejectsNonIntegerIdentityTypesWithoutAnyBatchWrite(string $field,mixed $value): void
    {
        Db::name('hotels')->insert(['id'=>1,'tenant_id'=>7,'name'=>'TEST-ONLY boolean target']);
        $originalRow=$this->row('2026-10-02 09:00:00',1);
        $original=$this->service()->saveSnapshots(7,[1,80],[$originalRow],9)['snapshots'][0];
        // Model a real existing identity 1, so bool true could otherwise adopt it as a correction.
        Db::name(BookingMonitoringService::TABLE)->where('id',$original['id'])->update(['id'=>1]);
        $original=$this->service()->readSnapshot(7,[1,80],80,1);
        $valid=$this->row('2026-10-02 10:00:00',2)+['idempotency_key'=>'TEST-ONLY-valid-before-type-error'];
        $invalid=array_replace($originalRow,[$field=>$value,'idempotency_key'=>'TEST-ONLY-invalid-identity']);
        if ($field==='hotel_id') $invalid['room_type_id']=0;
        $rejected=false;
        try { $this->service()->saveSnapshots(7,[1,80],[$valid,$invalid],9); }
        catch (InvalidArgumentException $error) {
            self::assertSame('booking_monitor_room_type_id_invalid',$error->getMessage());
            $rejected=true;
        }
        self::assertTrue($rejected,'Only integer/string identities can enter the prepared batch');
        self::assertSame(1,Db::name(BookingMonitoringService::TABLE)->count());
        self::assertSame($original,$this->service()->readSnapshot(7,[1,80],80,1));
    }

    public function testIntegerAndIntegerStringIdentitiesPreserveSaveReplayCorrectionAndReadback(): void
    {
        $row=array_replace($this->row('2026-10-02 09:00:00',1),[
            'hotel_id'=>'80','room_type_id'=>'1','supersedes_snapshot_id'=>'0','idempotency_key'=>'TEST-ONLY-string-identity']);
        $saved=$this->service()->saveSnapshots(7,[80],[$row],9)['snapshots'][0];
        self::assertSame(80,$saved['hotel_id']);
        self::assertSame(1,$saved['room_type_id']);
        self::assertNull($saved['supersedes_snapshot_id']);
        self::assertSame($saved,$this->service()->readSnapshot(7,[80],80,$saved['id'])+['idempotent'=>false]);
        $integerRow=array_replace($row,['hotel_id'=>80,'room_type_id'=>1,'supersedes_snapshot_id'=>0]);
        $replay=$this->service()->saveSnapshots(7,[80],[$integerRow],9)['snapshots'][0];
        self::assertSame($saved['id'],$replay['id']);
        self::assertSame($saved['content_digest'],$replay['content_digest']);
        $correction=array_replace($row,['supersedes_snapshot_id'=>(string)$saved['id'],
            'on_books_room_nights'=>2,'idempotency_key'=>'TEST-ONLY-string-correction']);
        $corrected=$this->service()->saveSnapshots(7,[80],[$correction],9)['snapshots'][0];
        self::assertSame($saved['id'],$corrected['supersedes_snapshot_id']);
        self::assertSame($corrected,$this->service()->readSnapshot(7,[80],80,$corrected['id'])+['idempotent'=>false]);
        self::assertSame($corrected['id'],$this->service()->saveSnapshots(7,[80],[$correction],9)['snapshots'][0]['id']);
    }

    public function testControllerRejectsIdentityTypesBeforeCoercionAndKeepsMixedBatchAtomic(): void
    {
        Db::name('hotels')->insert(['id'=>1,'tenant_id'=>7,'name'=>'TEST-ONLY boolean target']);
        $user=new class {
            public int $id=9;
            public function getPermittedHotelIds(): array { return [1,80]; }
            public function hasHotelPermission(int $hotelId,string $capability): bool { return in_array($hotelId,[1,80],true); }
        };
        $valid=$this->row('2026-10-02 09:00:00',1)+['idempotency_key'=>'TEST-ONLY-controller-valid-row'];
        foreach (self::invalidSnapshotIdentityTypes() as [$field,$value]) {
            $invalid=array_replace($valid,[$field=>$value,'idempotency_key'=>'TEST-ONLY-controller-bad-row']);
            if ($field==='hotel_id') $invalid['room_type_id']=0;
            $response=$this->controller(['rows'=>[$valid,$invalid]],$user,'POST')->saveSnapshots();
            self::assertSame(422,$response->getCode(),$response->getContent());
            self::assertFalse($response->getData()['data']['readback_verified']);
            self::assertSame(0,Db::name(BookingMonitoringService::TABLE)->count());
        }
    }

    public function testControllerReadRejectsBooleanFloatAndArrayIdentities(): void
    {
        Db::name('hotels')->insert(['id'=>1,'tenant_id'=>7,'name'=>'TEST-ONLY boolean target']);
        $saved=$this->service()->saveSnapshots(7,[1,80],[$this->row('2026-10-02 09:00:00',1)],9)['snapshots'][0];
        Db::name(BookingMonitoringService::TABLE)->where('id',$saved['id'])->update(['id'=>1]);
        $user=new class {
            public function getPermittedHotelIds(): array { return [1,80]; }
            public function hasHotelPermission(int $hotelId,string $capability): bool { return in_array($hotelId,[1,80],true); }
        };
        foreach (['hotel_id','id'] as $field) foreach ([true,false,1.0,80.0,[],[1],'1.0'] as $value) {
            $response=$this->controller(array_replace(['hotel_id'=>80,'id'=>1],[$field=>$value]),$user)->readSnapshot();
            self::assertSame(422,$response->getCode(),$response->getContent());
            self::assertFalse($response->getData()['data']['readback_verified']);
        }
        self::assertSame(200,$this->controller(['hotel_id'=>'80','id'=>'1'],$user)->readSnapshot()->getCode());
    }

    public function testOverviewRejectsNonIntegerScopeAndHorizonTypes(): void
    {
        Db::name('hotels')->insert(['id'=>1,'tenant_id'=>7,'name'=>'TEST-ONLY boolean target']);
        foreach ([true,false,1.0,80.0,[],[1],'1.0'] as $value) {
            $rejected=false;
            try { $this->service()->overview(7,[1,80],[$value],$this->query()); }
            catch (InvalidArgumentException $error) { $rejected=true; }
            self::assertTrue($rejected,'An invalid overview identity cannot name an authorized integer hotel');
            $rejected=false;
            try { $this->service()->overview(7,[80],[80],array_replace($this->query(),['horizon_days'=>$value])); }
            catch (InvalidArgumentException $error) { self::assertSame('booking_monitor_horizon_invalid',$error->getMessage()); $rejected=true; }
            self::assertTrue($rejected,'Display days must be an integer or integer-form string');
        }
        $view=$this->service()->overview(7,[80],['80'],array_replace($this->query(),['horizon_days'=>'1']));
        self::assertSame([80],$view['hotel_ids']);
        self::assertSame(1,$view['horizon_days']);
    }

    #[DataProvider('originalRetryCatalogueChanges')]
    public function testLostOriginalReceiptReplaysBeforeMutableRoomCatalogueChecks(?string $key, string $change): void
    {
        $row = $this->row('2026-10-02 09:00:00.123456', 10.1234);
        if ($key !== null) $row['idempotency_key'] = $key;
        $saved = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        if ($key === null) self::assertSame(hash('sha256', $saved['content_digest']), $saved['idempotency_key'], 'compatible legacy automatic-key derivation');
        if ($change === 'rename') Db::name('room_types')->where('id', 1)->update(['name' => 'TEST-ONLY changed after lost response']);
        elseif ($change === 'disable') Db::name('room_types')->where('id', 1)->update(['is_enabled' => 0]);
        else Db::name('room_types')->where('id', 1)->delete();
        $replayed = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        $expected = $saved;
        $expected['idempotent'] = true;
        self::assertSame($expected, $replayed);
        self::assertSame($saved, $this->service()->readSnapshot(7, [80], 80, $saved['id']) + ['idempotent' => false]);
        $recovery = $this->service(transactionRunner: static function (callable $callback): array {
            throw new RuntimeException('Deadlock found when trying to get lock', 1213);
        })->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertSame($expected, $recovery, 'winner recovery verifies the original saved name and digest too');
        self::assertSame(1, Db::name(BookingMonitoringService::TABLE)->count());
    }

    public static function originalRetryCatalogueChanges(): array
    {
        $cases = [];
        foreach ([null, 'TEST-ONLY-lost-original-receipt'] as $key) {
            foreach (['rename', 'disable', 'delete'] as $change) $cases[($key === null ? 'legacy automatic' : 'explicit') . ' after ' . $change] = [$key, $change];
        }
        return $cases;
    }

    public function testOriginalReplayCannotIgnoreChangedSubmittedIdentityOrLostPermissions(): void
    {
        $row = $this->row('2026-10-02 09:00:00.123456', 10.1234) + ['idempotency_key' => 'TEST-ONLY-strict-original-replay'];
        $saved = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        Db::name('room_types')->where('id', 1)->delete();
        foreach ([['on_books_room_nights' => 11], ['on_books_room_revenue' => 200], ['cumulative_cancel_room_nights' => 1],
            ['gross_booking_room_nights' => 20], ['source_ref' => 'TEST-ONLY-different-source'], ['source_method' => 'manual_entry'],
            ['operator_attested' => false], ['platform' => 'meituan'], ['stay_date' => '2026-10-04'],
            ['captured_at' => '2026-10-02 10:00:00.123456'], ['room_type_id' => 2]] as $changes) {
            try {
                $this->service()->saveSnapshots(7, [80], [array_replace($row, $changes)], 9);
                self::fail('same key must preserve submitted content after catalog deletion');
            } catch (RuntimeException $error) {
                self::assertSame('booking_monitor_idempotency_conflict', $error->getMessage());
            }
            self::assertSame(1, Db::name(BookingMonitoringService::TABLE)->count());
        }
        foreach ([[7, []], [8, [80]]] as [$tenantId, $permitted]) {
            try { $this->service()->saveSnapshots($tenantId, $permitted, [$row], 9); self::fail('replay never bypasses current authorization'); }
            catch (RuntimeException $error) { self::assertSame(403, $error->getCode()); }
        }
        self::assertSame($saved, $this->service()->readSnapshot(7, [80], 80, $saved['id']) + ['idempotent' => false]);
    }

    public function testChangedAutomaticRequestAndNewExplicitKeyRemainNewObservations(): void
    {
        $row = $this->row('2026-10-02 09:00:00.123456', 10.1234);
        $original = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        Db::name('room_types')->where('id', 1)->update(['name' => 'TEST-ONLY renamed enabled room']);
        $changed = $this->service()->saveSnapshots(7, [80], [array_replace($row, ['on_books_room_nights' => 11.1234])], 9)['snapshots'][0];
        $newKey = $this->service()->saveSnapshots(7, [80], [$row + ['idempotency_key' => 'TEST-ONLY-new-request-key']], 9)['snapshots'][0];
        self::assertNotSame($original['id'], $changed['id']);
        self::assertNotSame($original['id'], $newKey['id']);
        self::assertSame('TEST-ONLY renamed enabled room', $changed['room_type_name']);
        self::assertSame('TEST-ONLY renamed enabled room', $newKey['room_type_name']);
        Db::name('room_types')->where('id', 1)->update(['is_enabled' => 0]);
        try { $this->service()->saveSnapshots(7, [80], [array_replace($row, ['on_books_room_nights' => 12.1234])], 9); self::fail('new automatic observation still needs enabled room'); }
        catch (RuntimeException $error) { self::assertSame('booking_monitor_room_type_outside_hotel', $error->getMessage()); }
        self::assertSame(3, Db::name(BookingMonitoringService::TABLE)->count());
    }

    public function testAutomaticReplayUsesItsLegacyKeyRatherThanAnIdenticalExplicitSubmission(): void
    {
        $row = $this->row('2026-10-02 09:00:00.123456', 10.1234);
        $explicit = $this->service()->saveSnapshots(7, [80], [$row + ['idempotency_key' => 'TEST-ONLY-earlier-explicit']], 9)['snapshots'][0];
        $automatic = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertNotSame($explicit['id'], $automatic['id']);
        Db::name('room_types')->where('id', 1)->delete();
        $replay = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertSame($automatic['id'], $replay['id']);
        self::assertSame($automatic['content_digest'], $replay['content_digest']);
        self::assertTrue($replay['idempotent']);
        self::assertSame(2, Db::name(BookingMonitoringService::TABLE)->count());
    }

    #[DataProvider('automaticReplayRoomIdentities')]
    public function testAutomaticReplayRequiresExactRoomIdentityBeforeUsingSavedName(int $roomId, bool $enabled, ?string $expectedName): void
    {
        $row = $this->row('2026-10-02 09:00:00.123456', 10.1234);
        $service = $this->service();
        $original = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        Db::name('room_types')->where('id', 1)->delete();
        if (!$enabled) Db::name('room_types')->where('id', $roomId)->update(['is_enabled' => 0]);
        $changedRoom = array_replace($row, ['room_type_id' => $roomId]);
        if ($expectedName === null) {
            try {
                $service->saveSnapshots(7, [80], [$changedRoom], 9);
                self::fail('a different room must pass its own enabled hotel catalogue check');
            } catch (RuntimeException $error) {
                self::assertSame('booking_monitor_room_type_outside_hotel', $error->getMessage());
                self::assertSame(403, $error->getCode());
            }
            self::assertSame(1, Db::name(BookingMonitoringService::TABLE)->count());
        } else {
            $saved = $service->saveSnapshots(7, [80], [$changedRoom], 9)['snapshots'][0];
            self::assertNotSame($original['id'], $saved['id']);
            self::assertSame($roomId, $saved['room_type_id']);
            self::assertSame($expectedName, $saved['room_type_name']);
            self::assertFalse($saved['idempotent']);
            self::assertSame($saved['id'], $service->saveSnapshots(7, [80], [$changedRoom], 9)['snapshots'][0]['id']);
            self::assertSame(2, Db::name(BookingMonitoringService::TABLE)->count());
        }
        $replay = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertSame($original['id'], $replay['id']);
        self::assertSame($original['room_type_name'], $replay['room_type_name']);
        self::assertSame($original['content_digest'], $replay['content_digest']);
        self::assertTrue($replay['idempotent']);
    }

    public static function automaticReplayRoomIdentities(): array
    {
        return [
            'enabled second room' => [2, true, 'TEST-ONLY双床'],
            'hotel aggregate' => [0, true, '酒店汇总'],
            'disabled second room' => [2, false, null],
            'nonexistent room' => [999, true, null],
            'room belonging to another hotel' => [3, true, null],
        ];
    }

    public function testAutomaticReplayRequiresExactCorrectionIdentity(): void
    {
        $row = $this->row('2026-10-02 09:00:00.123456', 10.1234);
        $service = $this->service();
        $original = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        Db::name('room_types')->where('id', 1)->update(['is_enabled' => 0]);
        $firstInput = array_replace($row, ['supersedes_snapshot_id' => $original['id']]);
        $first = $service->saveSnapshots(7, [80], [$firstInput], 9)['snapshots'][0];
        $secondInput = array_replace($row, ['supersedes_snapshot_id' => $first['id']]);
        $second = $service->saveSnapshots(7, [80], [$secondInput], 9)['snapshots'][0];
        self::assertCount(3, array_unique([$original['id'], $first['id'], $second['id']]));
        self::assertSame($original['id'], $first['supersedes_snapshot_id']);
        self::assertSame($first['id'], $second['supersedes_snapshot_id']);
        foreach ([[$row, $original], [$firstInput, $first], [$secondInput, $second]] as [$input, $saved]) {
            $replay = $service->saveSnapshots(7, [80], [$input], 9)['snapshots'][0];
            self::assertSame($saved['id'], $replay['id']);
            self::assertSame($saved['content_digest'], $replay['content_digest']);
            self::assertSame($saved['supersedes_snapshot_id'], $replay['supersedes_snapshot_id']);
            self::assertTrue($replay['idempotent']);
        }
        self::assertSame(3, Db::name(BookingMonitoringService::TABLE)->count());
    }

    public function testAutomaticReplaySelectsEarliestValidHistoricalDuplicateWithoutRewritingIt(): void
    {
        $row = $this->row('2026-10-02 09:00:00.123456', 10.1234);
        $service = $this->service();
        $original = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        // Reproduce the duplicate the previous writer created after a rename.
        $duplicate = Db::name(BookingMonitoringService::TABLE)->where('id', $original['id'])->find();
        unset($duplicate['id'], $duplicate['content_digest'], $duplicate['idempotency_key'], $duplicate['created_by'], $duplicate['created_at']);
        foreach (['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights'] as $field) $duplicate[$field] = (float)$duplicate[$field];
        $duplicate['room_type_name'] = 'TEST-ONLY old duplicate after rename';
        $digest = (new ReflectionMethod($service, 'digest'))->invoke($service, $duplicate);
        $duplicateId = Db::name(BookingMonitoringService::TABLE)->insertGetId($duplicate + [
            'content_digest' => $digest, 'idempotency_key' => hash('sha256', $digest), 'created_by' => 9, 'created_at' => $original['created_at']]);
        $service->readSnapshot(7, [80], 80, (int)$duplicateId);
        Db::name('room_types')->where('id', 1)->delete();
        $replay = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertSame($original['id'], $replay['id']);
        self::assertSame($original['content_digest'], $replay['content_digest']);
        self::assertSame(2, Db::name(BookingMonitoringService::TABLE)->count());
        self::assertSame($duplicate['room_type_name'], $service->readSnapshot(7, [80], 80, (int)$duplicateId)['room_type_name']);
    }

    public function testOriginalReplayVerifiesSavedNameDigestAndPreservesUnknownMetrics(): void
    {
        $row = array_replace($this->row('2026-10-02 09:00:00.123456', 10.1234), [
            'on_books_room_revenue' => null, 'cumulative_cancel_room_nights' => null, 'gross_booking_room_nights' => null]);
        $original = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        Db::name('room_types')->where('id', 1)->delete();
        $replay = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertSame($original['id'], $replay['id']);
        self::assertNull($replay['on_books_room_revenue']);
        self::assertNull($replay['cumulative_cancel_room_nights']);
        self::assertNull($replay['gross_booking_room_nights']);
        try { $this->service()->saveSnapshots(7, [80], [array_replace($row, ['on_books_room_revenue' => 0])], 9); self::fail('unknown and zero are different submitted facts'); }
        catch (RuntimeException $error) { self::assertSame('booking_monitor_room_type_outside_hotel', $error->getMessage()); }
        Db::name(BookingMonitoringService::TABLE)->where('id', $original['id'])->update(['room_type_name' => 'TEST-ONLY corrupt saved name']);
        $this->expectExceptionMessage('booking_monitor_content_digest_mismatch');
        $this->service()->saveSnapshots(7, [80], [$row], 9);
    }

    public function testOutOfRangeMetricsReturn422AndDoNotPartiallySaveBatch(): void
    {
        $user = new class {
            public int $id = 9;
            public function getPermittedHotelIds(): array { return [80]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool { return $hotelId === 80; }
        };
        foreach (['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights'] as $field) {
            $invalid = array_replace($this->row('2026-10-02 09:00:00', 10), [$field => 1e10]);
            $response = $this->controller(['rows' => [$this->row('2026-10-01 09:00:00', 8), $invalid]], $user, 'POST')->saveSnapshots();
            self::assertSame(422, $response->getCode());
            self::assertSame(422, $response->getData()['code']);
            self::assertSame($field . '_out_of_range', $response->getData()['data']['reason_code']);
            self::assertFalse($response->getData()['data']['readback_verified']);
            self::assertSame(0, Db::name(BookingMonitoringService::TABLE)->count());
        }
    }

    public function testSelectableHotelsAreSameTenantIntersectionWithExplicitPermissions(): void
    {
        $view = $this->service()->overview(7, [80, 81, 82], [80], $this->query());
        self::assertSame([80, 82], array_column($view['selectable_hotels'], 'id'));
        self::assertSame([7, 7], array_column($view['selectable_hotels'], 'tenant_id'));
        $limited = $this->service()->overview(7, [80, 81], [80], $this->query());
        self::assertSame([80], array_column($limited['selectable_hotels'], 'id'));
        $this->expectExceptionMessage('booking_monitor_hotel_tenant_scope_mismatch');
        $this->service()->overview(7, [80, 81, 82], [80, 81], $this->query());
    }

    public function testMatrixAllows1000CellsAndRejectsTheNextDimensionBeforeSnapshotReads(): void
    {
        for ($id = 100; $id < 147; $id++) Db::name('room_types')->insert(['id'=>$id,'hotel_id'=>80,'name'=>'TEST-ONLY-cap-'.$id]);
        $query = array_replace($this->query(), ['horizon_days'=>20]);
        $view = $this->service()->overview(7, [80], [80], $query);
        self::assertSame(1000, $view['cell_count'], '49 room types plus the hotel aggregate, over 20 days');
        Db::name('room_types')->insert(['id'=>147,'hotel_id'=>80,'name'=>'TEST-ONLY-one-over']);
        $queries = $this->captureSql(function () use ($query): void {
            try { $this->service()->overview(7, [80], [80], $query); }
            catch (RuntimeException $error) {
                self::assertSame(422, $error->getCode());
                self::assertSame('booking_monitor_cell_limit_narrow_scope', $error->getMessage());
                return;
            }
            self::fail('matrix must reject before constructing over 1000 cells');
        });
        $roomQueries = $this->selectQueriesFor($queries, 'room_types');
        self::assertCount(1, $roomQueries);
        self::assertMatchesRegularExpression('/LIMIT\s+50\b/i', $roomQueries[0], 'bounded metadata with one overflow sentinel');
        self::assertCount(0, $this->selectQueriesFor($queries, BookingMonitoringService::TABLE));
    }

    #[DataProvider('historicalDimensionChanges')]
    public function testHistoricalRoomDimensionAlsoCountsBeforeMatrixConstruction(string $change): void
    {
        for ($id = 100; $id < 148; $id++) Db::name('room_types')->insert(['id'=>$id,'hotel_id'=>80,'name'=>'TEST-ONLY-history-cap-'.$id]);
        $saved = $this->service()->saveSnapshots(7, [80], [$this->row('2026-10-02 09:00:00', 1, 147)], 9)['snapshots'][0];
        if ($change === 'disable') Db::name('room_types')->where('id', 147)->update(['is_enabled'=>0]);
        else Db::name('room_types')->where('id', 147)->delete();
        $rejected=false;
        try { $this->service()->overview(7, [80], [80], array_replace($this->query(), ['horizon_days'=>20])); }
        catch (RuntimeException $error) {
            self::assertSame(422, $error->getCode());
            self::assertSame('booking_monitor_cell_limit_narrow_scope', $error->getMessage());
            $rejected=true;
        }
        self::assertTrue($rejected,'historical dimensions must also fit the matrix budget');
        $view = $this->service()->overview(7, [80], [80], array_replace($this->query(), ['horizon_days'=>19]));
        self::assertSame(969, $view['cell_count']);
        self::assertSame($saved, $this->service()->readSnapshot(7, [80], 80, $saved['id'])+['idempotent'=>false]);
    }

    public static function historicalDimensionChanges(): array
    {
        return [['disable'], ['delete']];
    }

    public function testTwentyHotelsAtThirtyDaysIncludeEveryHotelAggregateInTheBudget(): void
    {
        $hotels=[80];
        for ($id=1000;$id<1019;$id++) {
            Db::name('hotels')->insert(['id'=>$id,'tenant_id'=>7,'name'=>'TEST-ONLY-budget-'.$id]);
            $hotels[]=$id;
        }
        $query=array_replace($this->query(),['horizon_days'=>30]);
        self::assertSame(660,$this->service()->overview(7,$hotels,$hotels,$query)['cell_count']);
        for ($id=100;$id<112;$id++) Db::name('room_types')->insert(['id'=>$id,'hotel_id'=>80,'name'=>'TEST-ONLY-budget-room-'.$id]);
        $this->expectExceptionMessage('booking_monitor_cell_limit_narrow_scope');
        $this->service()->overview(7,$hotels,$hotels,$query);
    }

    public function testBatchScopeCacheCannotAuthorizeALaterForeignHotelOrRoomAndWritesNothing(): void
    {
        $valid=$this->row('2026-10-02 09:00:00',1)+['idempotency_key'=>'TEST-ONLY-valid-before-invalid'];
        foreach ([[[80],['hotel_id'=>82,'room_type_id'=>3]], [[80,81],['hotel_id'=>81,'room_type_id'=>1]],
            [[80,82],['hotel_id'=>82,'room_type_id'=>1]]] as [$permitted,$foreign]) {
            $rejected=false;
            try { $this->service()->saveSnapshots(7,$permitted,[$valid,array_replace($valid,$foreign,['idempotency_key'=>'TEST-ONLY-foreign'])],9); }
            catch (RuntimeException $error) { self::assertSame(403,$error->getCode()); $rejected=true; }
            self::assertTrue($rejected);
            self::assertSame(0,Db::name(BookingMonitoringService::TABLE)->count());
        }
    }

    public function test200RowPreparationReusesDistinctHotelAndRoomReadsOnlyWithinThatCall(): void
    {
        $rows=[];
        for ($index=0; $index<200; $index++) $rows[]=$this->row('2026-10-02 09:00:00', $index+1)+['idempotency_key'=>'TEST-ONLY-batch-cache-'.$index];
        $preparing=true;
        $preparation=[];
        $service=$this->service(transactionRunner: static function (callable $callback) use (&$preparing): array {
            $preparing=false;
            return Db::transaction($callback);
        });
        $queries=$this->captureSql(function () use ($service,$rows): void {
            $saved=$service->saveSnapshots(7,[80],$rows,9);
            self::assertSame(200,$saved['row_count']);
        }, static function (string $sql) use (&$preparing,&$preparation): void { if ($preparing) $preparation[]=$sql; });
        self::assertCount(1,$this->selectQueriesFor($preparation,'hotels'));
        self::assertCount(1,$this->selectQueriesFor($preparation,'room_types'));
        self::assertSame(200,Db::name(BookingMonitoringService::TABLE)->count());
        self::assertGreaterThan(200,count($queries),'per-row immutable write/readback queries still execute');
        Db::name('room_types')->where('id',1)->update(['name'=>'TEST-ONLY-catalogue-updated-between-calls']);
        $fresh=$service->saveSnapshots(7,[80],[$this->row('2026-10-02 10:00:00',1)+['idempotency_key'=>'TEST-ONLY-fresh-call']],9)['snapshots'][0];
        self::assertSame('TEST-ONLY-catalogue-updated-between-calls',$fresh['room_type_name']);
        try { $service->saveSnapshots(7,[],[$rows[0]],9); self::fail('cached scope must not survive a subsequent call'); }
        catch (RuntimeException $error) { self::assertSame(403,$error->getCode()); }
    }

    public function test200CorrectionsReuseVerifiedOriginalWithoutRepeatedHotelOrSnapshotMetadataReads(): void
    {
        $originalRow=$this->row('2026-10-02 09:00:00',1);
        $original=$this->service()->saveSnapshots(7,[80],[$originalRow],9)['snapshots'][0];
        Db::name('room_types')->where('id',1)->update(['is_enabled'=>0]);
        $rows=[];
        for ($index=0;$index<200;$index++) $rows[]=array_replace($originalRow,[
            'on_books_room_nights'=>$index+2,'supersedes_snapshot_id'=>$original['id'],'idempotency_key'=>'TEST-ONLY-correction-cache-'.$index]);
        $preparing=true;
        $preparation=[];
        $service=$this->service(transactionRunner: static function (callable $callback) use (&$preparing): array {
            $preparing=false;
            return Db::transaction($callback);
        });
        $this->captureSql(function () use ($service,$rows,$original): void {
            $saved=$service->saveSnapshots(7,[80],$rows,9);
            self::assertSame(200,$saved['row_count']);
            foreach ($saved['snapshots'] as $receipt) {
                self::assertSame($original['id'],$receipt['supersedes_snapshot_id']);
                self::assertSame($original['room_type_name'],$receipt['room_type_name']);
            }
        },static function (string $sql) use (&$preparing,&$preparation): void { if ($preparing) $preparation[]=$sql; });
        self::assertCount(1,$this->selectQueriesFor($preparation,'hotels'));
        $correctionReads=array_values(array_filter($this->selectQueriesFor($preparation,BookingMonitoringService::TABLE),
            static fn(string $sql):bool=>!str_contains($sql,'idempotency_key')));
        self::assertCount(1,$correctionReads,'only the verified superseded receipt is cached, never new write/readback');
        self::assertLessThanOrEqual(1,count($this->selectQueriesFor($preparation,'room_types')));
        self::assertSame(201,Db::name(BookingMonitoringService::TABLE)->count());
    }

    public function testExactFixed24hIgnoresMoreRecentIntraDaySnapshotsAndKeepsRoomsSeparate(): void
    {
        $service = $this->service();
        $service->saveSnapshots(7, [80], [
            $this->row('2026-10-01 09:00:00', 8),
            $this->row('2026-10-02 09:00:00', 11),
            $this->row('2026-10-02 10:00:00', 99),
            $this->row('2026-10-01 09:00:00', 2, 2),
            $this->row('2026-10-02 09:00:00', 3, 2),
        ], 9);
        $view = $service->overview(7, [80], [80], $this->query());
        $bed = $this->cell($view, 80, 1);
        $twin = $this->cell($view, 80, 2);
        self::assertSame('ready', $bed['status']);
        self::assertSame(24.0, $bed['elapsed_hours']);
        self::assertSame(3.0, $bed['net_pickup_24h_room_nights']);
        self::assertSame(1.0, $twin['net_pickup_24h_room_nights']);
        self::assertSame(11.0, $bed['current']['on_books_room_nights']);
        self::assertSame('2026-10-02 09:00:00', $view['observation_time']);
        self::assertSame('2026-10-01 09:00:00', $view['baseline_time']);
        self::assertSame('Asia/Shanghai', $view['timezone']);
        self::assertFalse($view['boundaries']['automatic_pricing']);
        self::assertSame(0, $view['boundaries']['external_write_count']);
    }

    public function testFixed24hAndHistoryPreserveFourDecimalDifferences(): void
    {
        $service = $this->service();
        $before = $this->row('2026-10-01 09:00:00', 1);
        $current = $this->row('2026-10-02 09:00:00', 1.0001);
        $before['on_books_room_revenue'] = 1;
        $current['on_books_room_revenue'] = 1.0001;
        $rows = [$before, $current];
        for ($week = 1; $week <= 4; $week++) {
            $anchor = new DateTimeImmutable('2026-10-02 09:00:00', new DateTimeZone('Asia/Shanghai'));
            $history = $anchor->modify('-' . ($week * 7) . ' days');
            $row = $this->row($history->format('Y-m-d H:i:s'), 1);
            $row['stay_date'] = $history->modify('+1 day')->format('Y-m-d');
            $rows[] = $row;
        }
        $service->saveSnapshots(7, [80], $rows, 9);
        $cell = $this->cell($service->overview(7, [80], [80], $this->query()), 80, 1);
        self::assertSame('ready', $cell['status']);
        self::assertSame(0.0001, $cell['net_pickup_24h_room_nights']);
        self::assertSame(0.0001, $cell['gross_pickup_24h_room_nights']);
        self::assertSame(0.0001, $cell['room_revenue_delta_24h']);
        self::assertSame(1.0, $cell['same_lead_time_median_room_nights']);
        self::assertSame(0.0001, $cell['delta_vs_same_lead_time_median']);
        self::assertSame(4, $cell['history_coverage']);
    }

    public function testFixed24hGrossCounterResetCannotRemainComparable(): void
    {
        $before = $this->row('2026-10-01 09:00:00', 8);
        $current = $this->row('2026-10-02 09:00:00', 9);
        $before['cumulative_cancel_room_nights'] = 1;
        $before['gross_booking_room_nights'] = 10;
        $current['cumulative_cancel_room_nights'] = 2;
        $current['gross_booking_room_nights'] = 9;
        $service = $this->service();
        $service->saveSnapshots(7, [80], [$before, $current], 9);
        $cell = $this->cell($service->overview(7, [80], [80], $this->query()), 80, 1);
        self::assertSame('not_comparable', $cell['status']);
        self::assertNull($cell['net_pickup_24h_room_nights']);
        self::assertNull($cell['gross_pickup_24h_room_nights']);
        self::assertNull($cell['room_revenue_delta_24h']);
        self::assertContains('gross_booking_counter_reset_or_mismatch', $cell['data_gaps']);
    }

    public function testLateStaleAndApproximateObservationsNeverBecome24hPickup(): void
    {
        $service = $this->service();
        $service->saveSnapshots(7, [80], [
            $this->row('2026-10-01 09:00:00', 8, 1),
            $this->row('2026-10-02 09:05:00', 11, 1),
            $this->row('2026-10-02 08:45:00', 3, 2),
        ], 9);
        $view = $service->overview(7, [80], [80], $this->query());
        self::assertSame('late', $this->cell($view, 80, 1)['current']['status']);
        self::assertSame('approximate', $this->cell($view, 80, 2)['current']['status']);
        self::assertNull($this->cell($view, 80, 1)['net_pickup_24h_room_nights']);
        self::assertNull($this->cell($view, 80, 2)['net_pickup_24h_room_nights']);
        $service->saveSnapshots(7, [80], [$this->row('2026-10-01 08:00:00', 8, 0)], 9);
        $view = $service->overview(7, [80], [80], $this->query());
        self::assertSame('stale', $this->cell($view, 80, 0)['current']['status']);
        self::assertNull($this->cell($view, 80, 0)['net_pickup_24h_room_nights']);
    }

    public function testSameLeadTimeHistoryUsesPreviousFourWeekdaysAndNoOtherHotelOrRoom(): void
    {
        $service = $this->service();
        $rows = [$this->row('2026-10-02 09:00:00', 20), $this->row('2026-10-01 09:00:00', 18)];
        foreach ([1 => 8, 2 => 10, 3 => 12, 4 => 14] as $week => $rooms) {
            $anchor = new DateTimeImmutable('2026-10-02 09:00:00', new DateTimeZone('Asia/Shanghai'));
            $history = $anchor->modify('-' . ($week * 7) . ' days');
            $row = $this->row($history->format('Y-m-d H:i:s'), $rooms);
            $row['stay_date'] = $history->modify('+1 day')->format('Y-m-d');
            $rows[] = $row;
            $other = $row;
            $other['room_type_id'] = 2;
            $other['on_books_room_nights'] = 999;
            $rows[] = $other;
        }
        $service->saveSnapshots(7, [80], $rows, 9);
        $view = $service->overview(7, [80, 82], [80, 82], $this->query());
        $cell = $this->cell($view, 80, 1);
        self::assertSame(4, $cell['history_coverage']);
        self::assertSame('ready', $cell['history_status']);
        self::assertSame(11.0, $cell['same_lead_time_median_room_nights']);
        self::assertSame(9.0, $cell['delta_vs_same_lead_time_median']);
        self::assertSame(1, $cell['history'][0]['lead_time_days']);
        self::assertSame('ready', $cell['baseline_readiness']['status']);
        self::assertCount(6, $cell['baseline_readiness']['requirements']);
        self::assertSame([], $cell['baseline_readiness']['gaps']);
        self::assertSame(1, $view['baseline_readiness']['complete_cell_count']);
        self::assertNull($this->cell($view, 82, 3)['current']['on_books_room_nights']);
        self::assertSame('missing', $this->cell($view, 82, 3)['history_status']);
        $missing=$this->cell($view,82,3)['baseline_readiness'];
        self::assertSame('incomplete',$missing['status']);self::assertCount(6,$missing['gaps']);
        self::assertSame('2026-09-25 09:00:00',$missing['gaps'][2]['target_time']);
        self::assertSame('2026-09-26',$missing['gaps'][2]['stay_date']);
    }

    public function testOldSummariesRemainUnsplitAndIncompleteHistoryKeepsNullMedian(): void
    {
        $planning = new BookingDemandPlanningService(static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-02 12:00:00', new DateTimeZone('Asia/Shanghai')));
        foreach (['2026-10-01 09:00:00' => 8, '2026-10-02 09:00:00' => 10] as $capture => $rooms) {
            $row = $this->row($capture, $rooms, 0);
            $row['source_method'] = 'manual_entry';
            $row['quality_status'] = 'manual_confirmed';
            $row['idempotency_key'] = hash('sha256', 'TEST-ONLY-legacy-' . $capture);
            $planning->saveOnBooksSnapshot(7, [80], 80, $row, 9);
        }
        $view = $this->service()->overview(7, [80], [80], $this->query());
        $summary = $this->cell($view, 80, 0);
        self::assertSame(2.0, $summary['net_pickup_24h_room_nights']);
        self::assertStringStartsWith('hotel_on_books_snapshots#', $summary['current']['evidence_ref']);
        self::assertNull($this->cell($view, 80, 1)['current']['on_books_room_nights']);
        self::assertNull($summary['same_lead_time_median_room_nights']);
        self::assertSame('missing', $summary['history_status']);
    }

    public function testUnverifiedAndMissingBaselineKeepNumericMetricsNull(): void
    {
        $row = $this->row('2026-10-02 09:00:00', 0);
        $row['operator_attested'] = false;
        $this->service()->saveSnapshots(7, [80], [$row], 9);
        $cell = $this->cell($this->service()->overview(7, [80], [80], $this->query()), 80, 1);
        self::assertSame('unverified', $cell['status']);
        self::assertSame(0.0, $cell['current']['on_books_room_nights']);
        self::assertNull($cell['net_pickup_24h_room_nights']);
        self::assertSame('missing', $cell['baseline']['status']);
    }

    public function testImportPrevalidatesAllRowsAndRejectsCrossHotelOrTenantWithoutPartialWrite(): void
    {
        $bad = $this->row('2026-10-02 09:00:00', 5);
        $bad['hotel_id'] = 82;
        try {
            $this->service()->saveSnapshots(7, [80], [$this->row('2026-10-01 09:00:00', 4), $bad], 9);
            self::fail('cross-hotel import must fail');
        } catch (RuntimeException $error) {
            self::assertSame(403, $error->getCode());
            self::assertSame(0, Db::name(BookingMonitoringService::TABLE)->count());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('booking_monitor_hotel_tenant_scope_mismatch');
        $this->service()->overview(7, [80, 81], [80, 81], $this->query());
    }

    public function testRoomTypeCannotBeImportedUnderAnotherHotel(): void
    {
        $row = $this->row('2026-10-02 09:00:00', 5);
        $row['room_type_id'] = 3;
        $this->expectExceptionMessage('booking_monitor_room_type_outside_hotel');
        $this->service()->saveSnapshots(7, [80], [$row], 9);
    }

    public function testFractionalHotelIdentityIsRejectedAndFourDecimalImportHasMatchingStorageContract(): void
    {
        $row = $this->row('2026-10-02 09:00:00', 10.1234);
        $saved = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertSame(10.1234, $saved['on_books_room_nights']);
        $migration = file_get_contents(dirname(__DIR__) . '/database/migrations/20261002_create_room_type_on_books_snapshots.sql');
        self::assertStringContainsString('`on_books_room_nights` DECIMAL(14,4)', $migration);
        self::assertStringContainsString('`on_books_room_revenue` DECIMAL(18,4)', $migration);
        $row['hotel_id'] = 80.5;
        $this->expectExceptionMessage('booking_monitor_room_type_id_invalid');
        $this->service()->saveSnapshots(7, [80], [$row], 9);
    }

    public function testCorrectionAppendsAndReplaysWithoutOverwritingOriginalEvidence(): void
    {
        $service = $this->service();
        $row = $this->row('2026-10-02 09:00:00', 9);
        $saved = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        $row['on_books_room_nights'] = 11;
        $row['supersedes_snapshot_id'] = $saved['id'];
        $correction = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertSame(2, Db::name(BookingMonitoringService::TABLE)->count());
        self::assertSame(9.0, $service->readSnapshot(7, [80], 80, $saved['id'])['on_books_room_nights']);
        self::assertSame($saved['id'], $correction['supersedes_snapshot_id']);
        self::assertSame(11.0, $this->cell($service->overview(7, [80], [80], $this->query()), 80, 1)['current']['on_books_room_nights']);
        $row['stay_date'] = '2026-10-04';
        $this->expectExceptionMessage('booking_monitor_correction_scope_mismatch');
        $service->saveSnapshots(7, [80], [$row], 9);
    }

    public function testDeletedRoomTypeCorrectionAppendsWithExactReadbackAndImmutableOriginal(): void
    {
        $service = $this->service();
        $row = $this->row('2026-10-02 09:00:00.123456', 10.1234);
        $original = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        Db::name('room_types')->where('id', 1)->delete();
        $history = $this->cell($service->overview(7, [80], [80], $this->query()), 80, 1);
        self::assertSame('TEST-ONLY大床（历史房型）', $history['room_type_name']);
        self::assertSame(10.1234, $history['current']['on_books_room_nights']);

        $row['supersedes_snapshot_id'] = $original['id'];
        $row['source_ref'] = 'TEST-ONLY retired room correction';
        $row['on_books_room_nights'] = 11.1234;
        $row['on_books_room_revenue'] = null;
        $row['cumulative_cancel_room_nights'] = null;
        $row['gross_booking_room_nights'] = null;
        $corrected = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        $read = $service->readSnapshot(7, [80], 80, $corrected['id']);
        self::assertNotSame($original['id'], $corrected['id']);
        self::assertSame($original['id'], $read['supersedes_snapshot_id']);
        self::assertSame($original['room_type_name'], $read['room_type_name']);
        self::assertSame(11.1234, $read['on_books_room_nights']);
        self::assertNull($read['on_books_room_revenue']);
        self::assertNull($read['cumulative_cancel_room_nights']);
        self::assertNull($read['gross_booking_room_nights']);
        self::assertSame('2026-10-02 09:00:00.123456', $read['captured_at']);
        self::assertSame($corrected, $read + ['idempotent' => false]);
        self::assertSame($original, $service->readSnapshot(7, [80], 80, $original['id']) + ['idempotent' => false]);
        $replay = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertSame($corrected['id'], $replay['id']);
        self::assertTrue($replay['idempotent']);
        self::assertSame(2, Db::name(BookingMonitoringService::TABLE)->count());
    }

    public function testLateCorrectionChainUsesLatestVersionOfEarliestLateCaptureWithoutChangingOriginals(): void
    {
        $service = $this->service();
        $service->saveSnapshots(7, [80], [$this->row('2026-10-01 09:00:00', 8)], 9);
        $row = $this->row('2026-10-02 09:05:00', 10);
        $original = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        $later = $service->saveSnapshots(7, [80], [$this->row('2026-10-02 09:10:00', 99)], 9)['snapshots'][0];
        $row['on_books_room_nights'] = 12;
        $row['supersedes_snapshot_id'] = $original['id'];
        $first = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        $row['on_books_room_nights'] = 13;
        $row['supersedes_snapshot_id'] = $first['id'];
        $latest = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];

        $cell = $this->cell($service->overview(7, [80], [80], $this->query()), 80, 1);
        self::assertSame('late', $cell['current']['status']);
        self::assertSame('2026-10-02 09:05:00.000000', $cell['current']['captured_at']);
        self::assertSame(13.0, $cell['current']['on_books_room_nights']);
        self::assertSame($latest['evidence_ref'], $cell['current']['evidence_ref']);
        self::assertNull($cell['net_pickup_24h_room_nights']);
        self::assertNull($cell['gross_pickup_24h_room_nights']);
        self::assertNull($cell['room_revenue_delta_24h']);
        foreach ([$original, $later, $first] as $saved) {
            self::assertSame($saved, $service->readSnapshot(7, [80], 80, $saved['id']) + ['idempotent' => false]);
        }
    }

    public function testEqualLateCaptureUsesRoomTypePriorityThenLatestIdentityInsteadOfLaterCapture(): void
    {
        $original = $this->row('2026-10-02 09:05:00.000000', 10) + [
            'id' => 999, '_priority' => 0, 'quality_status' => 'manual_confirmed', 'readback_verified' => 1,
            'source_method' => 'manual_file_import', 'evidence_ref' => 'TEST-ONLY legacy#999',
        ];
        $new = array_replace($original, ['id' => 1, '_priority' => 1, 'on_books_room_nights' => 12.0, 'evidence_ref' => 'TEST-ONLY room#1']);
        $correction = array_replace($new, ['id' => 2, 'on_books_room_nights' => 13.0, 'evidence_ref' => 'TEST-ONLY room#2']);
        $later = array_replace($correction, ['id' => 3, 'captured_at' => '2026-10-02 09:10:00.000000', 'on_books_room_nights' => 99.0]);
        $cell = $this->service()->compareSlots(7, 80, 'ctrip', '2026-10-03',
            new DateTimeImmutable('2026-10-02 09:00:00', new DateTimeZone('Asia/Shanghai')), [$later, $correction, $original, $new]);
        self::assertSame('late', $cell['current']['status']);
        self::assertSame('TEST-ONLY room#2', $cell['current']['evidence_ref']);
        self::assertSame(13.0, $cell['current']['on_books_room_nights']);
        self::assertNull($cell['net_pickup_24h_room_nights']);
    }

    #[DataProvider('historicalCorrectionCatalogueChanges')]
    public function testHistoricalCorrectionReplaysAfterRoomRenamesAndDeletion(?string $idempotencyKey, string $catalogueChange): void
    {
        $service = $this->service();
        $row = $this->row('2026-10-02 09:00:00.123456', 10.1234);
        $original = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        Db::name('room_types')->where('id', 1)->update(['name' => 'TEST-ONLY renamed before correction']);
        $row['supersedes_snapshot_id'] = $original['id'];
        $row['source_ref'] = 'TEST-ONLY historical correction replay';
        $row['on_books_room_nights'] = 11.1234;
        $row['on_books_room_revenue'] = 1123.4567;
        if ($idempotencyKey !== null) $row['idempotency_key'] = $idempotencyKey;
        $corrected = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        if ($catalogueChange === 'rename') {
            Db::name('room_types')->where('id', 1)->update(['name' => 'TEST-ONLY renamed again after correction']);
        } else {
            Db::name('room_types')->where('id', 1)->delete();
        }

        $replay = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertSame($corrected['id'], $replay['id']);
        self::assertTrue($replay['idempotent']);
        $expectedReplay = $corrected;
        $expectedReplay['idempotent'] = true;
        self::assertSame($expectedReplay, $replay);
        self::assertSame($original['room_type_name'], $corrected['room_type_name']);
        self::assertSame($original['id'], $corrected['supersedes_snapshot_id']);
        self::assertSame(11.1234, $corrected['on_books_room_nights']);
        self::assertSame(1123.4567, $corrected['on_books_room_revenue']);
        self::assertSame($original, $service->readSnapshot(7, [80], 80, $original['id']) + ['idempotent' => false]);
        self::assertSame($corrected, $service->readSnapshot(7, [80], 80, $corrected['id']) + ['idempotent' => false]);
        self::assertSame(2, Db::name(BookingMonitoringService::TABLE)->count());
    }

    public static function historicalCorrectionCatalogueChanges(): array
    {
        return [
            'default key after another rename' => [null, 'rename'],
            'default key after deletion' => [null, 'delete'],
            'explicit key after another rename' => ['TEST-ONLY-historical-correction-replay', 'rename'],
            'explicit key after deletion' => ['TEST-ONLY-historical-correction-replay', 'delete'],
        ];
    }

    public function testDeletedRoomTypeCannotPermitNewFactsOrCorrectionScopeChanges(): void
    {
        $service = $this->service();
        $row = $this->row('2026-10-02 09:00:00', 10);
        $row['platform'] = 'manual_all_channels';
        $row['fact_scope'] = 'accommodation_room_fee';
        $original = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        Db::name('room_types')->where('id', 1)->delete();
        $row['supersedes_snapshot_id'] = $original['id'];
        $row['on_books_room_nights'] = 11;
        $cases = [
            [7, [80], ['supersedes_snapshot_id' => null], 'booking_monitor_room_type_outside_hotel'],
            [7, [80], ['room_type_id' => 2], 'booking_monitor_correction_room_type_mismatch'],
            [7, [80, 82], ['hotel_id' => 82], 'booking_monitor_snapshot_not_found'],
            [8, [81], ['hotel_id' => 81], 'booking_monitor_snapshot_not_found'],
            [7, [80], ['platform' => 'dingdandao_pms'], 'booking_monitor_correction_scope_mismatch'],
            [7, [80], ['stay_date' => '2026-10-04'], 'booking_monitor_correction_scope_mismatch'],
            [7, [80], ['captured_at' => '2026-10-02 09:01:00'], 'booking_monitor_correction_scope_mismatch'],
            [7, [80], ['fact_scope' => 'whole_hotel'], 'booking_monitor_correction_scope_mismatch'],
        ];
        foreach ($cases as [$tenantId, $permitted, $changes, $reason]) {
            try {
                $service->saveSnapshots($tenantId, $permitted, [array_replace($row, $changes)], 9);
                self::fail('deleted room correction must preserve scope: ' . json_encode($changes));
            } catch (InvalidArgumentException | RuntimeException $error) {
                self::assertSame($reason, $error->getMessage());
                self::assertSame(1, Db::name(BookingMonitoringService::TABLE)->count());
            }
        }
        self::assertSame($original, $service->readSnapshot(7, [80], 80, $original['id']) + ['idempotent' => false]);
    }

    public function testSameReplayKeyWithDifferentContentRollsBackEntireBatch(): void
    {
        $service = $this->service();
        $row = $this->row('2026-10-02 09:00:00', 9);
        $row['idempotency_key'] = 'TEST-ONLY-replay-key';
        $service->saveSnapshots(7, [80], [$row], 9);
        $row['on_books_room_nights'] = 11;
        try {
            $service->saveSnapshots(7, [80], [$this->row('2026-10-01 09:00:00', 8), $row], 9);
            self::fail('conflicting replay must fail');
        } catch (RuntimeException $error) {
            self::assertSame('booking_monitor_idempotency_conflict', $error->getMessage());
            self::assertSame(1, Db::name(BookingMonitoringService::TABLE)->count());
        }
    }

    public function testCorruptReadbackFailsInsteadOfReturningHistoricalFallback(): void
    {
        $saved = $this->service()->saveSnapshots(7, [80], [$this->row('2026-10-02 09:00:00', 9)], 9)['snapshots'][0];
        Db::name(BookingMonitoringService::TABLE)->where('id', $saved['id'])->update(['on_books_room_nights' => 99]);
        $this->expectExceptionMessage('booking_monitor_content_digest_mismatch');
        $this->service()->overview(7, [80], [80], $this->query());
    }

    public function testFutureDatesAndMissingNumbersFailAndPendingAnchorIsExplicit(): void
    {
        $view = $this->service('2026-10-02 08:00:00')->overview(7, [80], [80], $this->query());
        self::assertContains('fixed_observation_time_not_reached', $this->cell($view, 80, 1)['data_gaps']);
        self::assertSame('not_due',$this->cell($view,80,1)['current']['status']);
        self::assertSame('not_due',$this->cell($view,80,1)['baseline_readiness']['gaps'][0]['status']);
        foreach ([['business_date' => '2026-02-30'], ['business_date' => '2026-10-03'], ['fixed_time' => '24:01'], ['horizon_days' => 0]] as $invalid) {
            try { $this->service()->overview(7, [80], [80], $invalid + $this->query()); self::fail('invalid date/slot/horizon'); }
            catch (InvalidArgumentException $error) { self::assertStringStartsWith('booking_monitor_', $error->getMessage()); }
        }
        $row = $this->row('2026-10-02 09:00:00', 4);
        $row['on_books_room_nights'] = null;
        $this->expectExceptionMessage('on_books_room_nights_required');
        $this->service()->saveSnapshots(7, [80], [$row], 9);
    }

    public function testDifferentFactScopesCannotBecomeComparable24hOrHistory(): void
    {
        $service = $this->service();
        $before = $this->row('2026-10-01 09:00:00', 8);
        $after = $this->row('2026-10-02 09:00:00', 9);
        $before['platform'] = $after['platform'] = 'manual_all_channels';
        $before['fact_scope'] = 'whole_hotel';
        $after['fact_scope'] = 'accommodation_room_fee';
        $service->saveSnapshots(7, [80], [$before, $after], 9);
        $cell = $this->cell($service->overview(7, [80], [80], ['platform' => 'manual_all_channels'] + $this->query()), 80, 1);
        self::assertSame('not_comparable', $cell['status']);
        self::assertNull($cell['net_pickup_24h_room_nights']);
        self::assertContains('on_books_fact_scope_changed', $cell['data_gaps']);
    }

    public function testControllerRequiresLoginAndExecutionPermissionBeforeAnyWrite(): void
    {
        $anonymous = $this->controller(['hotel_ids' => '80'], null)->overview();
        self::assertSame(401, $anonymous->getCode());
        self::assertSame(401, $anonymous->getData()['code']);
        $user = new class {
            public int $id = 9;
            public function getPermittedHotelIds(): array { return [80]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool { return $hotelId === 80 && $capability === 'operation.view'; }
        };
        $denied = $this->controller(['rows' => [$this->row('2026-10-02 09:00:00', 8)]], $user, 'POST')->saveSnapshots();
        self::assertSame(403, $denied->getCode());
        self::assertSame('booking_monitor_hotel_outside_permitted_scope', $denied->getData()['data']['reason_code']);
        self::assertSame(0, Db::name(BookingMonitoringService::TABLE)->count());
    }

    public function testControllerReadbackIsBoundToExplicitPermittedHotel(): void
    {
        $snapshot = $this->service()->saveSnapshots(7, [80], [$this->row('2026-10-02 09:00:00', 8)], 9)['snapshots'][0];
        $user = new class {
            public int $id = 9;
            public function getPermittedHotelIds(): array { return [80]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool { return $hotelId === 80; }
        };
        $readback = $this->controller(['hotel_id' => 80, 'id' => $snapshot['id']], $user)->readSnapshot();
        self::assertSame(200, $readback->getData()['code']);
        self::assertSame($snapshot['content_digest'], $readback->getData()['data']['content_digest']);
        $other = $this->controller(['hotel_id' => 82, 'id' => $snapshot['id']], $user)->readSnapshot();
        self::assertSame(403, $other->getCode());
        self::assertFalse($other->getData()['data']['readback_verified']);
    }

    private function controller(array $params, ?object $user, string $method = 'GET'): \app\controller\BookingMonitoring
    {
        $class = new ReflectionClass(\app\controller\BookingMonitoring::class);
        $controller = $class->newInstanceWithoutConstructor();
        $request = new class($params, $method) {
            public function __construct(private array $values, private string $verb) {}
            public function param(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
            public function post(): array { return $this->verb === 'POST' ? $this->values : []; }
            public function method(): string { return $this->verb; }
            public function getContent(): string { return ''; }
        };
        $class->getProperty('request')->setValue($controller, $request);
        $class->getProperty('currentUser')->setValue($controller, $user);
        return $controller;
    }

    private function service(string $now = '2026-10-02 12:00:00', ?callable $transactionRunner = null): BookingMonitoringService
    {
        return new BookingMonitoringService(static fn(): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('Asia/Shanghai')), $transactionRunner);
    }

    /** Capture actual SQL for this operation and restore ORM observers/config afterwards. */
    private function captureSql(callable $operation, ?callable $observer = null): array
    {
        $connection=Db::connect();
        $configProperty=new ReflectionProperty($connection,'config');
        $originalConfig=$configProperty->getValue($connection);
        $manager=think\Container::getInstance()->make(think\DbManager::class);
        $listenProperty=new ReflectionProperty($manager,'listen');
        $originalListeners=$listenProperty->getValue($manager);
        $queries=[];
        $configProperty->setValue($connection,array_replace($originalConfig,['trigger_sql'=>true]));
        Db::listen(static function (string $sql) use (&$queries,$observer): void {
            $queries[]=$sql;
            if ($observer !== null) $observer($sql);
        });
        try { $operation(); }
        finally {
            $configProperty->setValue($connection,$originalConfig);
            $listenProperty->setValue($manager,$originalListeners);
        }
        return $queries;
    }

    private function selectQueriesFor(array $queries,string $table): array
    {
        return array_values(array_filter($queries,static fn(string $sql):bool=>
            preg_match('/^SELECT\b.*\bFROM\s+[`"]?'.preg_quote($table,'/').'[`"]?\b/i',$sql) === 1));
    }

    private function row(string $capturedAt, float $rooms, int $roomId = 1): array
    {
        return ['hotel_id' => 80, 'room_type_id' => $roomId, 'platform' => 'ctrip', 'fact_scope' => 'ota_channel',
            'stay_date' => '2026-10-03', 'captured_at' => $capturedAt, 'on_books_room_nights' => $rooms,
            'on_books_room_revenue' => $rooms * 100, 'cumulative_cancel_room_nights' => 0, 'gross_booking_room_nights' => $rooms,
            'source_ref' => 'TEST-ONLY-synthetic-source-' . $capturedAt . '-' . $roomId, 'operator_attested' => true];
    }

    private function query(): array
    {
        return ['platform' => 'ctrip', 'business_date' => '2026-10-02', 'fixed_time' => '09:00', 'horizon_days' => 1];
    }

    private function cell(array $view, int $hotelId, int $roomId): array
    {
        foreach ($view['cells'] as $cell) if ($cell['hotel_id'] === $hotelId && $cell['room_type_id'] === $roomId) return $cell;
        self::fail('cell missing');
    }

    public function testControllerRejectsFractionalReadbackIdentitiesInsteadOfTruncatingThem(): void
    {
        $snapshot = $this->service()->saveSnapshots(7, [80], [$this->row('2026-10-02 09:00:00', 8)], 9)['snapshots'][0];
        $user = new class {
            public int $id = 9;
            public function getPermittedHotelIds(): array { return [80]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool { return $hotelId === 80; }
        };
        foreach ([['hotel_id' => 80.5, 'id' => $snapshot['id']], ['hotel_id' => 80, 'id' => $snapshot['id'] + 0.5]] as $params) {
            $response = $this->controller($params, $user)->readSnapshot();
            self::assertSame(422, $response->getCode());
            self::assertFalse($response->getData()['data']['readback_verified']);
        }
    }

    public function testImportRejectsNumbersOutsideDeclaredDecimalStorageBeforeAnyBatchWrite(): void
    {
        foreach (['on_books_room_nights' => 1e10, 'on_books_room_revenue' => 1e14,
            'cumulative_cancel_room_nights' => 1e10, 'gross_booking_room_nights' => 1e10] as $field => $value) {
            $row = $this->row('2026-10-02 09:00:00', 4);
            $row[$field] = $value;
            try {
                $this->service()->saveSnapshots(7, [80], [$this->row('2026-10-01 09:00:00', 3), $row], 9);
                self::fail($field . ' must be rejected before persistence');
            } catch (InvalidArgumentException $error) {
                self::assertSame($field . '_out_of_range', $error->getMessage());
                self::assertSame(0, Db::name(BookingMonitoringService::TABLE)->count());
            }
        }
    }

    public function testFractionalOverviewHotelScopeCannotResolveToAnIntegerHotel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->overview(7, [80], [80.5], $this->query());
    }

    public function testDefaultBusinessDateAndNineOClockUseShanghaiWhenClockReturnsUtc(): void
    {
        $utcClock = new BookingMonitoringService(static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-01 23:59:59', new DateTimeZone('UTC')));
        $query = $this->query(); unset($query['business_date']);
        $view = $utcClock->overview(7, [80], [80], $query);
        self::assertSame('2026-10-02', $view['business_date']);
        self::assertSame('2026-10-02 09:00:00', $view['observation_time']);
        self::assertContains('fixed_observation_time_not_reached', $this->cell($view, 80, 1)['data_gaps']);
        $atNine = new BookingMonitoringService(static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-02 01:00:00', new DateTimeZone('UTC')));
        $atNine->saveSnapshots(7, [80], [$this->row('2026-10-01 09:00:00', 4), $this->row('2026-10-02 09:00:00', 5)], 9);
        $cell = $this->cell($atNine->overview(7, [80], [80], $query), 80, 1);
        self::assertSame('ready', $cell['status']); self::assertSame(24.0, $cell['elapsed_hours']); self::assertSame(1.0, $cell['net_pickup_24h_room_nights']);
    }

    public function testControllerDefaultBusinessDateDoesNotInheritServerTimezone(): void
    {
        $user = new class {
            public int $id = 9;
            public function getPermittedHotelIds(): array { return [80]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool { return $hotelId === 80; }
        };
        $shanghai = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set((int)$shanghai->format('H') < 20 ? 'Etc/GMT+12' : 'Pacific/Kiritimati');
            $response = $this->controller(['hotel_ids' => '80', 'horizon_days' => 1], $user)->overview();
            self::assertSame(200, $response->getCode());
            self::assertSame($shanghai->format('Y-m-d'), $response->getData()['data']['business_date']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }
}
