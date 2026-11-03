<?php
declare(strict_types=1);

namespace OfflineTrafficSqlite {
    use PDO;
    final class Db
    {
        public static PDO $pdo;
        public static function reset(bool $legacy = false): void
        {
            self::$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $optional = $legacy ? '' : ', tenant_id INTEGER, platform TEXT, dimension TEXT';
            self::$pdo->exec('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY AUTOINCREMENT,
                hotel_id TEXT, hotel_name TEXT, system_hotel_id INTEGER, data_date TEXT, source TEXT,
                data_type TEXT, data_period TEXT, snapshot_bucket TEXT, data_value REAL' . $optional . ')');
            self::$pdo->exec('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
            self::$pdo->exec('INSERT INTO hotels VALUES (7,1),(8,2),(9,1)');
        }
        public static function name(string $table): Query
        {
            if (!in_array($table, ['online_daily_data', 'hotels'], true)) {
                throw new \RuntimeException('Unexpected fixture table');
            }
            return new Query(self::$pdo, $table);
        }
        public static function columns(): array
        {
            return array_fill_keys(array_column(self::$pdo->query('PRAGMA table_info(online_daily_data)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
        }
        public static function rows(): array
        {
            return self::$pdo->query('SELECT * FROM online_daily_data ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    final class Query
    {
        private array $conditions = [];
        public function __construct(private PDO $pdo, private string $table) {}
        private function identifier(string $name): string
        {
            if (preg_match('/^[a-z_]+$/D', $name) !== 1) {
                throw new \RuntimeException('Unexpected SQL identifier');
            }
            return '"' . $name . '"';
        }
        public function where(string $field, $value): self
        {
            $this->conditions[] = [$field, $value];
            return $this;
        }
        private function predicate(): string
        {
            if ($this->conditions === []) return '';
            return ' WHERE ' . implode(' AND ', array_map(fn(array $c): string => $this->identifier($c[0]) . ' = ?', $this->conditions));
        }
        public function find()
        {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->identifier($this->table) . $this->predicate() . ' ORDER BY id LIMIT 1');
            $stmt->execute(array_column($this->conditions, 1));
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        public function value(string $field)
        {
            $row = $this->find();
            return is_array($row) ? ($row[$field] ?? null) : null;
        }
        public function update(array $data): int
        {
            if ($this->conditions === []) throw new \RuntimeException('Unscoped fixture update');
            $set = implode(', ', array_map(fn(string $key): string => $this->identifier($key) . ' = ?', array_keys($data)));
            $stmt = $this->pdo->prepare('UPDATE ' . $this->identifier($this->table) . ' SET ' . $set . $this->predicate());
            $stmt->execute(array_merge(array_values($data), array_column($this->conditions, 1)));
            return $stmt->rowCount();
        }
        public function insertGetId(array $data): int
        {
            $keys = implode(', ', array_map(fn(string $key): string => $this->identifier($key), array_keys($data)));
            $stmt = $this->pdo->prepare('INSERT INTO ' . $this->identifier($this->table) . ' (' . $keys . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')');
            $stmt->execute(array_values($data));
            return (int)$this->pdo->lastInsertId();
        }
    }
}

namespace {
    use OfflineTrafficSqlite\Db;
    date_default_timezone_set('Asia/Shanghai');
    // No application/bootstrap/configuration is loaded. SQLite DSN is fixed above.
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/service/OnlineDailyDataPersistenceService.php');
    if ($source === false) throw new RuntimeException('Missing isolated source');
    function productionMethod(string $source, string $name): string
    {
        $pattern = '/^    (?:public|private) (?:static )?function ' . preg_quote($name, '/') . '\([^)]*\)[^{]*\{.*?^    \}/ms';
        if (preg_match($pattern, $source, $match) !== 1) throw new RuntimeException('Missing production method: ' . $name);
        return $match[0];
    }
    $method = productionMethod($source, 'parseAndSaveGenericTrafficData');
    if (preg_match('/            \$query = Db::name\(\x27online_daily_data\x27\).*?            \$exists = \$query->find\(\);/s', $method, $lookup) !== 1
        || preg_match('/            if \(\$exists\) \{.*?            \} else \{.*?            \}/s', $method, $upsert) !== 1) {
        throw new RuntimeException('Cannot extract actual production lookup/upsert');
    }
    $helpers = implode("\n", array_map(fn(string $name): string => productionMethod($source, $name),
        ['applyPeriodQuery', 'normalizePeriod', 'resolveTenantIdForSystemHotel']));
    // SQL predicates and the update/insert branch are copied verbatim from production.
    // Metric extraction, period derivation, validation and readback proof are outside this focused harness.
    eval('namespace app\\service; use OfflineTrafficSqlite\\Db; final class OfflineTrafficIdentityHarness {' . $helpers . '
        public function write(array $row, float $value): int {
            $columns = Db::columns();
            $itemDate = $row["data_date"];
            $source = $row["source"];
            $platform = $row["platform"];
            $dimension = $row["dimension"];
            $hotelId = $row["hotel_id"];
            $hotelName = $row["hotel_name"];
            $systemHotelId = $row["system_hotel_id"];
            $periodFilter = $row;
            ' . $lookup[0] . '
            $data = array_intersect_key(array_replace($row, ["data_value" => $value]), $columns);
            if (isset($columns["tenant_id"])) $data["tenant_id"] = self::resolveTenantIdForSystemHotel($systemHotelId);
            ' . $upsert[0] . '
            return $rowId;
        }
    }');
    $harness = new \app\service\OfflineTrafficIdentityHarness();
    $base = ['hotel_id' => 'synthetic-ota-hotel', 'hotel_name' => 'Synthetic hotel',
        'system_hotel_id' => 7, 'tenant_id' => 1, 'data_date' => '2026-09-01',
        'source' => 'synthetic-api', 'platform' => 'meituan', 'dimension' => 'list_exposure',
        'data_type' => 'traffic', 'data_period' => 'historical_daily', 'snapshot_bucket' => ''];
    function expectSame($expected, $actual, string $label): void
    {
        if ($expected !== $actual) throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', actual ' . var_export($actual, true));
    }
    function expectValues(array $values): void
    {
        expectSame($values, array_map(fn(array $row): float => (float)$row['data_value'], Db::rows()), 'stored fixture values');
    }
    $cases = [];
    foreach ([
        'dimension isolates rows' => ['dimension' => 'detail_exposure'],
        'platform isolates rows even with same source' => ['platform' => 'ctrip'],
        'system hotel and tenant isolate reused OTA hotel ID' => ['system_hotel_id' => 8],
        'OTA hotel ID isolates rows' => ['hotel_id' => 'synthetic-other-hotel'],
        'business date isolates rows' => ['data_date' => '2026-09-02'],
        'source isolates rows' => ['source' => 'synthetic-other-api'],
        'period lookup remains separate' => ['data_period' => 'realtime_snapshot', 'snapshot_bucket' => '2026-09-01T12:00'],
    ] as $label => $changes) {
        $cases[$label] = static function () use ($harness, $base, $changes): void {
            Db::reset();
            $harness->write($base, 11);
            $harness->write(array_replace($base, $changes), 22);
            expectSame(2, count(Db::rows()), 'independent identities retain two rows');
            expectValues([11.0, 22.0]);
        };
    }
    $cases['same complete identity updates idempotently'] = static function () use ($harness, $base): void {
        Db::reset();
        $id = $harness->write($base, 11);
        expectSame($id, $harness->write($base, 22), 'same row on update');
        expectSame($id, $harness->write($base, 22), 'same row on repeated update');
        expectSame(1, count(Db::rows()), 'no duplicate identity');
        expectValues([22.0]);
    };
    $cases['foreign tenant legacy row is never repurposed'] = static function () use ($harness, $base): void {
        Db::reset();
        Db::name('online_daily_data')->insertGetId(array_replace($base, ['tenant_id' => 2, 'data_value' => 99]));
        $harness->write($base, 11);
        expectSame(2, count(Db::rows()), 'tenant predicate protects inconsistent legacy row');
        expectSame([2, 1], array_map(fn(array $r): int => (int)$r['tenant_id'], Db::rows()), 'foreign tenant unchanged');
        expectValues([99.0, 11.0]);
    };
    $cases['realtime buckets remain isolated and same bucket updates'] = static function () use ($harness, $base): void {
        Db::reset();
        $a = array_replace($base, ['data_period' => 'realtime_snapshot', 'snapshot_bucket' => '2026-09-01T12:00']);
        $b = array_replace($a, ['snapshot_bucket' => '2026-09-01T13:00']);
        $harness->write($a, 11);
        $id = $harness->write($b, 22);
        expectSame($id, $harness->write($b, 33), 'same bucket row');
        expectSame(2, count(Db::rows()), 'distinct buckets');
        expectValues([11.0, 33.0]);
    };
    $cases['hotel name fallback retains system hotel isolation'] = static function () use ($harness, $base): void {
        Db::reset();
        $a = array_replace($base, ['hotel_id' => '']);
        $harness->write($a, 11);
        $harness->write(array_replace($a, ['system_hotel_id' => 8]), 22);
        expectSame(2, count(Db::rows()), 'same name different system hotel');
        expectValues([11.0, 22.0]);
    };
    $cases['default traffic dimension is independent'] = static function () use ($harness, $base): void {
        Db::reset();
        $harness->write(array_replace($base, ['dimension' => 'traffic']), 11);
        $harness->write($base, 22);
        expectSame(2, count(Db::rows()), 'default versus measured dimension');
        expectValues([11.0, 22.0]);
    };
    $cases['schema without optional identity columns remains supported'] = static function () use ($harness, $base): void {
        Db::reset(true);
        $id = $harness->write($base, 11);
        expectSame($id, $harness->write($base, 22), 'legacy same identity update');
        expectSame(1, count(Db::rows()), 'legacy query uses existing columns');
        expectValues([22.0]);
    };
    $cases['invalid system hotel never writes a tenant scoped row'] = static function () use ($harness, $base): void {
        Db::reset();
        $thrown = false;
        try {
            $harness->write(array_replace($base, ['system_hotel_id' => null]), 11);
        } catch (\InvalidArgumentException) {
            $thrown = true;
        }
        expectSame(true, $thrown, 'invalid tenant scope rejected');
        expectSame([], Db::rows(), 'no unscoped write');
    };
    $passed = 0; $failed = 0; $index = 0;
    echo 'TAP version 13', PHP_EOL, '1..', count($cases), PHP_EOL;
    foreach ($cases as $label => $run) {
        ++$index;
        try {
            $run(); ++$passed;
            echo 'ok ', $index, ' - ', $label, PHP_EOL;
        } catch (\Throwable $e) {
            ++$failed;
            echo 'not ok ', $index, ' - ', $label, PHP_EOL;
            echo '# ', str_replace(["\r", "\n"], ' ', $e->getMessage()), PHP_EOL;
        }
    }
    echo 'SUMMARY ', json_encode(['tests' => count($cases), 'pass' => $passed, 'fail' => $failed,
        'php' => PHP_VERSION, 'driver' => 'PDO SQLite', 'sqlite' => Db::$pdo->query('SELECT sqlite_version()')->fetchColumn(),
        'timezone' => date_default_timezone_get(), 'scope' => 'production lookup/upsert and period/tenant helpers'], JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($failed === 0 ? 0 : 1);
}
