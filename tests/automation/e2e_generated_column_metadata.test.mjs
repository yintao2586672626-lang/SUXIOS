import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const php = process.env.SUXI_PHP || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
const probe = String.raw`
namespace think\facade {
    class Db {
        public static array $rows = [];
        public static bool $fail = false;
        public static int $queries = 0;
        public static function query(string $sql, array $bindings): array {
            self::$queries++;
            if (self::$fail) throw new \RuntimeException('synthetic private connection details');
            return self::$rows;
        }
    }
}
namespace {
    $source = file_get_contents('tests/automation/e2e-isolation-helper.php');
    $start = strpos($source, 'function e2eTableColumnMetadata(');
    $end = strpos($source, 'function e2eHasColumn(', $start);
    if ($start === false || $end === false) throw new \RuntimeException('Actual metadata helpers are missing');
    eval('use think\\facade\\Db;' . substr($source, $start, $end - $start));
    \think\facade\Db::$rows = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
    \think\facade\Db::$fail = $argv[2] === '1';
    try {
        $payload = e2eFilterPayload($argv[3], ['history_status' => 'success', 'create_time' => '2026-09-30 00:00:00', 'unknown_column' => 'reject']);
        echo json_encode(['payload' => $payload, 'queries' => \think\facade\Db::$queries], JSON_THROW_ON_ERROR);
    } catch (\Throwable $error) {
        echo json_encode(['error' => $error->getMessage(), 'queries' => \think\facade\Db::$queries], JSON_THROW_ON_ERROR);
    }
}`;
function filter(rows, { fail = false, table = 'fixture_schema' } = {}) {
  const result = spawnSync(php, ['-r', probe, JSON.stringify(rows), fail ? '1' : '0', table], { encoding: 'utf8', windowsHide: true });
  assert.equal(result.status, 0, result.stderr || result.error?.message);
  return JSON.parse(result.stdout);
}
const columns = (extra, expression = '') => [
  { Field: 'history_status', Extra: extra, GenerationExpression: expression },
  { Field: 'create_time', Extra: 'DEFAULT_GENERATED', GenerationExpression: '' },
];

test('actual helper preserves ordinary MySQL DEFAULT_GENERATED columns and writable history status', () => {
  const result = filter(columns(''));
  assert.deepEqual(result.payload, { history_status: 'success', create_time: '2026-09-30 00:00:00' });
  assert.equal(result.queries, 1);
});

for (const [extra, expression] of [['STORED GENERATED', ''], ['VIRTUAL GENERATED', ''], ['', "CASE WHEN readback_verified = 1 THEN 'success' ELSE 'unverified' END"]]) {
  test(`actual helper excludes generated status identified by ${extra || 'its expression'}`, () => {
    assert.deepEqual(filter(columns(extra, expression)).payload, { create_time: '2026-09-30 00:00:00' });
  });
}

test('metadata query failure rejects the write rather than treating unknown columns as writable', () => {
  const result = filter(columns(''), { fail: true });
  assert.equal(result.error, 'Isolated E2E column metadata lookup failed for fixture_schema');
  assert.equal(Object.hasOwn(result, 'payload'), false);
  assert.doesNotMatch(result.error, /private connection details/);
});

test('invalid table identifiers never issue metadata SQL or retain a write payload', () => {
  const result = filter(columns(''), { table: 'fixture_schema; DROP TABLE hotels' });
  assert.equal(result.queries, 0);
  assert.deepEqual(result.payload, []);
});
