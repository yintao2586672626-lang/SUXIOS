<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

function read_text(string $relative): string
{
    global $root;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: {$relative}");
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Cannot read file: {$relative}");
    }

    if (str_starts_with($raw, "\xFF\xFE") && function_exists('iconv')) {
        $converted = iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
        if ($converted !== false) {
            return $converted;
        }
    }

    return str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
}

function load_env_file(): array
{
    $env = [];
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($path)) {
        return $env;
    }

    foreach (preg_split("/\r\n|\n|\r/", (string) file_get_contents($path)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $env[$key] = trim($value, "\"'");
    }

    return $env;
}

function parse_options(array $argv): array
{
    $env = load_env_file();
    $options = [
        'host' => $env['DB_HOST'] ?? '127.0.0.1',
        'port' => $env['DB_PORT'] ?? '3306',
        'database' => $env['DB_NAME'] ?? 'hotelx',
        'user' => $env['DB_USER'] ?? 'root',
        'password' => $env['DB_PASS'] ?? '',
        'charset' => $env['DB_CHARSET'] ?? 'utf8mb4',
        'mysql-bin' => null,
        'migrate' => false,
        'validate-import' => false,
        'import' => false,
        'force-import' => false,
        'report' => null,
        'json' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$key, $value] = explode('=', $arg, 2);
                if (array_key_exists($key, $options)) {
                    $options[$key] = $value;
                }
                continue;
            }
            if (array_key_exists($arg, $options)) {
                $options[$arg] = true;
            }
        }
    }

    return $options;
}

function init_full_sql_files(): array
{
    $sql = read_text('database/init_full.sql');
    if (!preg_match_all('/^\s*SOURCE\s+(.+?);/im', $sql, $matches)) {
        throw new RuntimeException('database/init_full.sql does not declare any SOURCE files.');
    }

    $files = [];
    foreach ($matches[1] as $source) {
        $file = trim($source, " \t\r\n'\"");
        if (str_starts_with($file, './')) {
            $file = substr($file, 2);
        }
        $files[] = str_replace('\\', '/', $file);
    }

    return array_values(array_unique($files));
}

function project_sql_files(): array
{
    global $root;
    $files = init_full_sql_files();
    $known = array_fill_keys($files, true);
    $migrationPaths = glob($root . '/database/migrations/*.sql') ?: [];
    usort($migrationPaths, static fn(string $left, string $right): int => strcmp(basename($left), basename($right)));
    foreach ($migrationPaths as $path) {
        $relative = 'database/migrations/' . basename($path);
        if (!isset($known[$relative])) {
            $files[] = $relative;
            $known[$relative] = true;
        }
    }
    return $files;
}

function register_baseline_and_apply_pending(PDO $pdo): array
{
    global $root;
    $service = new \app\service\SchemaVersionService($pdo, $root);
    $baselineRegistered = $service->baselineInitFullSources();
    $result = $service->migrate();
    return [
        'baseline_registered' => $baselineRegistered,
        'executed' => $result['executed'],
        'status' => $result['status'],
    ];
}

function parse_sql_resources(array $files): array
{
    global $root;
    return \app\service\SqlSchemaResourceInspector::parse($root, $files);
}

function model_table_map(): array
{
    $map = [];
    foreach (glob(__DIR__ . '/../app/model/*.php') ?: [] as $path) {
        $source = (string) file_get_contents($path);
        if (!preg_match('/class\s+(\w+)/', $source, $class)) {
            continue;
        }
        if (
            preg_match('/protected\s+\$table\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $table)
            || preg_match('/protected\s+\$name\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $table)
        ) {
            $map[$class[1]] = $table[1];
        }
    }
    ksort($map);
    return $map;
}

function add_ref(array &$refs, string $table, string $location): void
{
    if ($table === '') {
        return;
    }
    $refs[$table][] = $location;
    $refs[$table] = array_values(array_unique($refs[$table]));
}

function add_column_ref(array &$refs, string $table, string $column, string $location): void
{
    if ($table === '' || $column === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
        return;
    }
    $refs[$table][$column][] = $location;
    $refs[$table][$column] = array_values(array_unique($refs[$table][$column]));
}

function parse_field_list(string $fields): array
{
    $result = [];
    foreach (parse_field_references($fields) as $reference) {
        $result[] = $reference['column'];
    }
    return array_values(array_unique($result));
}

function normalize_field_reference(string $field): string
{
    $field = preg_replace('/\s+as\s+[A-Za-z_][A-Za-z0-9_]*$/i', '', $field) ?? $field;
    return trim($field, " \t\n\r\0\x0B`");
}

function parse_field_references(string $fields): array
{
    $result = [];
    $seen = [];
    foreach (explode(',', $fields) as $field) {
        $field = normalize_field_reference($field);
        if ($field === '' || str_contains($field, '(')) {
            continue;
        }
        $tableAlias = '';
        if (str_contains($field, '.')) {
            [$tableAlias, $field] = explode('.', $field, 2);
        }
        $tableAlias = trim($tableAlias, '` ');
        $field = trim($field, '` ');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
            $key = $tableAlias . '.' . $field;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = ['alias' => $tableAlias, 'column' => $field];
            }
        }
    }
    return $result;
}

function query_alias_tables(string $baseTable, string $chain): array
{
    $aliases = ['' => $baseTable];
    if (preg_match('/->alias\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\)/', $chain, $alias)) {
        $aliases[$alias[1]] = $baseTable;
    }

    if (preg_match_all('/->(?:leftJoin|rightJoin|join)\(\s*[\'"]([^\'"]+)[\'"]/', $chain, $joins)) {
        foreach ($joins[1] as $joinTarget) {
            $joinTarget = trim($joinTarget);
            if (preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?(?:\s+(?:as\s+)?`?([A-Za-z_][A-Za-z0-9_]*)`?)?$/i', $joinTarget, $match)) {
                $table = $match[1];
                $alias = $match[2] ?? $table;
                $aliases[$alias] = $table;
                $aliases[$table] = $table;
            }
        }
    }

    return $aliases;
}

function add_query_field_refs(array &$columns, string $baseTable, string $chain, string $fieldText, string $location): void
{
    $aliasTables = query_alias_tables($baseTable, $chain);
    foreach (parse_field_references($fieldText) as $reference) {
        $alias = $reference['alias'];
        if ($alias !== '' && !isset($aliasTables[$alias])) {
            continue;
        }
        add_column_ref($columns, $aliasTables[$alias] ?? $baseTable, $reference['column'], $location);
    }
}

function top_level_array_keys(string $body): array
{
    $items = [];
    $current = '';
    $depth = 0;
    $quote = null;
    $escape = false;
    $length = strlen($body);

    for ($i = 0; $i < $length; $i++) {
        $char = $body[$i];
        $current .= $char;

        if ($quote !== null) {
            if ($escape) {
                $escape = false;
            } elseif ($char === '\\') {
                $escape = true;
            } elseif ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === '\'' || $char === '"') {
            $quote = $char;
        } elseif ($char === '[' || $char === '(') {
            $depth++;
        } elseif (($char === ']' || $char === ')') && $depth > 0) {
            $depth--;
        } elseif ($char === ',' && $depth === 0) {
            $items[] = substr($current, 0, -1);
            $current = '';
        }
    }
    if (trim($current) !== '') {
        $items[] = $current;
    }

    $keys = [];
    foreach ($items as $item) {
        if (preg_match('/^\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*=>/', $item, $match)) {
            $keys[] = $match[1];
        }
    }

    return array_values(array_unique($keys));
}

function collect_code_refs(array $modelMap, array $criticalColumns): array
{
    $tables = [];
    $columns = [];

    foreach (glob(__DIR__ . '/../app/model/*.php') ?: [] as $path) {
        $source = (string) file_get_contents($path);
        if (!preg_match('/class\s+(\w+)/', $source, $class) || !isset($modelMap[$class[1]])) {
            continue;
        }
        $table = $modelMap[$class[1]];
        $relative = 'app/model/' . basename($path);
        add_ref($tables, $table, $relative);

        if (preg_match('/protected\s+\$type\s*=\s*\[(.*?)\];/s', $source, $typeBlock)) {
            if (preg_match_all('/[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*=>/', $typeBlock[1], $matches)) {
                foreach ($matches[1] as $column) {
                    add_column_ref($columns, $table, $column, "{$relative}:type");
                }
            }
        }
        if (preg_match('/protected\s+\$json\s*=\s*\[(.*?)\];/s', $source, $jsonBlock)) {
            if (preg_match_all('/[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $jsonBlock[1], $matches)) {
                foreach ($matches[1] as $column) {
                    add_column_ref($columns, $table, $column, "{$relative}:json");
                }
            }
        }
    }

    foreach (['app', 'scripts', 'route'] as $directory) {
        $base = __DIR__ . '/../' . $directory;
        if (!is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen(dirname(__DIR__)) + 1));
            $source = (string) file_get_contents($path);

            if (preg_match_all('/Db::(?:name|table)\(\s*[\'"]([^\'"]+)[\'"]\s*\)(?P<chain>.*?);/s', $source, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $table = $match[1];
                    add_ref($tables, $table, $relative);
                    $chain = $match['chain'];
                    if (preg_match_all('/->(?:where|whereOr|whereIn|whereNotIn|whereBetween|whereNull|whereNotNull|order|group|column)\(\s*[\'"]([^\'"]+)[\'"]/', $chain, $fieldMatches)) {
                        foreach ($fieldMatches[1] as $fieldText) {
                            add_query_field_refs($columns, $table, $chain, $fieldText, "{$relative}:query");
                        }
                    }
                    if (preg_match_all('/->field\(\s*[\'"]([^\'"]+)[\'"]/', $chain, $fieldMatches)) {
                        foreach ($fieldMatches[1] as $fieldText) {
                            add_query_field_refs($columns, $table, $chain, $fieldText, "{$relative}:field");
                        }
                    }
                    if (preg_match_all('/->(?:insert|insertGetId|update)\(\s*\[(?P<body>.*?)\]\s*\)/s', $chain, $arrayMatches, PREG_SET_ORDER)) {
                        foreach ($arrayMatches as $arrayMatch) {
                            foreach (top_level_array_keys($arrayMatch['body']) as $column) {
                                add_column_ref($columns, $table, $column, "{$relative}:write");
                            }
                        }
                    }
                }
            }

            foreach ($modelMap as $class => $table) {
                if (preg_match_all('/\b' . preg_quote($class, '/') . '::(?:where|whereIn|whereBetween|field|column)\(\s*[\'"]([^\'"]+)[\'"]/', $source, $fieldMatches)) {
                    add_ref($tables, $table, $relative);
                    foreach ($fieldMatches[1] as $fieldText) {
                        foreach (parse_field_list($fieldText) as $column) {
                            add_column_ref($columns, $table, $column, "{$relative}:model_query");
                        }
                    }
                }
                if (preg_match_all('/\b' . preg_quote($class, '/') . '::(?:create|update)\(\s*\[(?P<body>.*?)\]/s', $source, $arrayMatches, PREG_SET_ORDER)) {
                    add_ref($tables, $table, $relative);
                    foreach ($arrayMatches as $arrayMatch) {
                        foreach (top_level_array_keys($arrayMatch['body']) as $column) {
                            add_column_ref($columns, $table, $column, "{$relative}:model_write");
                        }
                    }
                }
            }
        }
    }

    foreach ($criticalColumns as $table => $tableColumns) {
        add_ref($tables, $table, 'critical_columns');
        foreach ($tableColumns as $column) {
            add_column_ref($columns, $table, $column, 'critical_columns');
        }
    }

    ksort($tables);
    ksort($columns);
    return ['tables' => $tables, 'columns' => $columns];
}

function missing_columns(array $schema, array $requiredColumns, array $tables): array
{
    $missing = [];
    foreach ($requiredColumns as $table => $columns) {
        if (!in_array($table, $tables, true)) {
            continue;
        }
        foreach (array_keys($columns) as $column) {
            if (!in_array($column, $schema[$table] ?? [], true)) {
                $missing[] = "{$table}.{$column}";
            }
        }
    }
    sort($missing);
    return $missing;
}

function find_mysql_bin(?string $configured): string
{
    $candidates = array_filter([
        $configured,
        'C:\xampp\mysql\bin\mysql.exe',
        'D:\xampp\mysql\bin\mysql.exe',
        'mysql',
    ]);

    foreach ($candidates as $candidate) {
        if ($candidate === 'mysql') {
            exec('where mysql 2>nul', $output, $code);
            if ($code === 0) {
                return 'mysql';
            }
            continue;
        }
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('mysql client not found');
}

function pdo_server(array $options): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $options['host'], $options['port'], $options['charset']);
    return new PDO($dsn, (string) $options['user'], (string) $options['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function pdo_database(array $options): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $options['host'], $options['port'], $options['database'], $options['charset']);
    return new PDO($dsn, (string) $options['user'], (string) $options['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function quote_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function ensure_database(array $options): void
{
    $pdo = pdo_server($options);
    $charset = preg_match('/^[A-Za-z0-9_]+$/', (string) $options['charset']) ? $options['charset'] : 'utf8mb4';
    $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . quote_identifier((string) $options['database']) . ' CHARACTER SET ' . $charset . ' COLLATE ' . $charset . '_unicode_ci');
}

function create_isolated_database(array $options): void
{
    $pdo = pdo_server($options);
    $database = (string)$options['database'];
    $charset = preg_match('/^[A-Za-z0-9_]+$/', (string)$options['charset']) ? $options['charset'] : 'utf8mb4';
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $statement->execute([$database]);
    if ((int)$statement->fetchColumn() !== 0) {
        throw new RuntimeException("Isolated validation database already exists: {$database}");
    }
    $pdo->exec(
        'CREATE DATABASE ' . quote_identifier($database)
        . ' CHARACTER SET ' . $charset . ' COLLATE ' . $charset . '_unicode_ci'
    );
}

function mysql_command(string $mysqlBin, array $options, string $database, string $statement): array
{
    global $root;
    $parts = [
        escapeshellarg($mysqlBin),
        '--default-character-set=' . escapeshellarg((string) $options['charset']),
        '-h ' . escapeshellarg((string) $options['host']),
        '-P ' . escapeshellarg((string) $options['port']),
        '-u ' . escapeshellarg((string) $options['user']),
    ];
    if ((string) $options['password'] !== '') {
        $parts[] = '--password=' . escapeshellarg((string) $options['password']);
    }
    $parts[] = escapeshellarg($database);
    $parts[] = '--execute=' . escapeshellarg($statement);

    $cwd = getcwd();
    chdir($root);
    exec(implode(' ', $parts) . ' 2>&1', $output, $code);
    chdir($cwd ?: $root);

    return ['code' => $code, 'output' => $output];
}

function import_init_full(string $database, array $options, string $mysqlBin): void
{
    $result = mysql_command($mysqlBin, $options, $database, 'SOURCE ./database/init_full.sql');
    $text = implode(PHP_EOL, $result['output']);
    if ($result['code'] !== 0 || preg_match('/\bERROR\b/i', $text)) {
        throw new RuntimeException("database/init_full.sql import failed\n" . $text);
    }
}

function inspect_database(PDO $pdo, string $database): array
{
    $schema = [];
    $indexes = [];

    $stmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
    $stmt->execute([$database]);
    foreach ($stmt->fetchAll() as $row) {
        $schema[$row['TABLE_NAME']] = [];
        $indexes[$row['TABLE_NAME']] = [];
    }

    $stmt = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION');
    $stmt->execute([$database]);
    foreach ($stmt->fetchAll() as $row) {
        $schema[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
    }

    $stmt = $pdo->prepare('SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? GROUP BY TABLE_NAME, INDEX_NAME');
    $stmt->execute([$database]);
    foreach ($stmt->fetchAll() as $row) {
        $indexes[$row['TABLE_NAME']][] = $row['INDEX_NAME'];
    }

    return ['schema' => $schema, 'indexes' => $indexes];
}

function execute_schema_migration(PDO $pdo, array $resources, array $actual, string $database): array
{
    $executed = [];
    $errors = [];

    foreach ($resources['creates'] as $table => $sql) {
        if (!isset($actual['schema'][$table])) {
            try {
                $pdo->exec($sql);
                $executed[] = "CREATE TABLE {$table}";
            } catch (Throwable $e) {
                $errors[] = "CREATE TABLE {$table}: " . $e->getMessage();
            }
        }
    }

    $actual = inspect_database($pdo, $database);
    foreach ($resources['schema'] as $table => $columns) {
        if (!isset($actual['schema'][$table])) {
            continue;
        }
        foreach ($columns as $column) {
            if (in_array($column, $actual['schema'][$table], true)) {
                continue;
            }
            $definition = $resources['columns'][$table][$column] ?? null;
            if ($definition === null) {
                $errors[] = "ADD COLUMN {$table}.{$column}: source definition missing";
                continue;
            }
            try {
                $pdo->exec('ALTER TABLE ' . quote_identifier($table) . ' ADD COLUMN IF NOT EXISTS ' . $definition);
                $executed[] = "ADD COLUMN {$table}.{$column}";
            } catch (Throwable $e) {
                $errors[] = "ADD COLUMN {$table}.{$column}: " . $e->getMessage();
            }
        }
    }

    $actual = inspect_database($pdo, $database);
    foreach ($resources['indexes'] as $table => $tableIndexes) {
        if (!isset($actual['schema'][$table])) {
            continue;
        }
        foreach ($tableIndexes as $indexName => $definition) {
            if (in_array($indexName, $actual['indexes'][$table] ?? [], true)) {
                continue;
            }
            try {
                $pdo->exec('ALTER TABLE ' . quote_identifier($table) . ' ' . $definition);
                $executed[] = "ADD INDEX {$table}.{$indexName}";
            } catch (Throwable $e) {
                $errors[] = "ADD INDEX {$table}.{$indexName}: " . $e->getMessage();
            }
        }
    }

    return ['executed' => $executed, 'errors' => $errors, 'actual' => inspect_database($pdo, $database)];
}

function summarize_ota_fields(array $schema): array
{
    $required = ['platform', 'compare_type', 'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'];
    $existing = $schema['online_daily_data'] ?? [];
    return [
        'required' => $required,
        'missing' => array_values(array_diff($required, $existing)),
        'count' => count(array_intersect($required, $existing)),
    ];
}

function build_report(array $summary): string
{
    $lines = [
        '# SQL Schema Integrity Report',
        '',
        '- Generated at: ' . date('Y-m-d H:i:s'),
        '- Database: `' . $summary['database'] . '`',
        '- Full SQL table count: ' . $summary['full_sql_table_count'],
        '- Actual table count: ' . ($summary['actual_table_count'] ?? 'not checked'),
        '- Code-required table count: ' . $summary['code_required_table_count'],
        '- Migration success: ' . ($summary['migration_success'] ? 'yes' : 'no'),
        '- Full import validation: ' . ($summary['import_validation'] ?? 'not run'),
        '- OTA traffic fields: ' . ($summary['ota_missing'] ? 'missing ' . implode(', ', $summary['ota_missing']) : 'ok'),
        '',
        '## Missing After Migration',
        '',
        '- Tables: ' . ($summary['missing_tables_after'] ? implode(', ', $summary['missing_tables_after']) : 'none'),
        '- Fields: ' . ($summary['missing_columns_after'] ? implode(', ', $summary['missing_columns_after']) : 'none'),
        '',
        '## Migration Actions',
        '',
        $summary['executed'] ? implode(PHP_EOL, array_map(static fn(string $item): string => '- ' . $item, $summary['executed'])) : '- none',
    ];

    if ($summary['errors']) {
        $lines[] = '';
        $lines[] = '## Errors';
        $lines[] = '';
        foreach ($summary['errors'] as $error) {
            $lines[] = '- ' . $error;
        }
    }

    if ($summary['warnings']) {
        $lines[] = '';
        $lines[] = '## Warnings';
        $lines[] = '';
        foreach ($summary['warnings'] as $warning) {
            $lines[] = '- ' . $warning;
        }
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

try {
    $options = parse_options($argv);
    $sqlFiles = project_sql_files();
    $resources = parse_sql_resources($sqlFiles);
    $baselineSqlFile = 'database/hotel_admin_mysql.sql';
    $baselineResources = parse_sql_resources([$baselineSqlFile]);
    $modelMap = model_table_map();
    $criticalColumns = [
        'online_daily_data' => ['platform', 'compare_type', 'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'],
        'devices' => ['last_maintenance_date', 'next_maintenance_date'],
        'energy_consumption' => ['anomaly_level', 'benchmark_id'],
        'price_suggestions' => ['demand_forecast_id'],
    ];
    $refs = collect_code_refs($modelMap, $criticalColumns);
    $optionalTables = ['store'];
    $requiredTables = array_values(array_diff(array_keys($refs['tables']), $optionalTables));
    sort($requiredTables);

    $sourceMissingTables = array_values(array_diff($requiredTables, array_keys($resources['schema'])));
    $sourceMissingColumns = missing_columns($resources['schema'], $refs['columns'], $requiredTables);
    $baselineMissingTables = array_values(array_diff($requiredTables, array_keys($baselineResources['schema'])));
    $baselineMissingColumns = missing_columns($baselineResources['schema'], $refs['columns'], $requiredTables);

    $summary = [
        'database' => (string) $options['database'],
        'source_sql_files' => $sqlFiles,
        'baseline_sql_file' => $baselineSqlFile,
        'full_sql_table_count' => count($resources['schema']),
        'code_required_table_count' => count($requiredTables),
        'source_missing_tables' => $sourceMissingTables,
        'source_missing_columns' => $sourceMissingColumns,
        'baseline_missing_tables' => $baselineMissingTables,
        'baseline_missing_columns' => $baselineMissingColumns,
        'actual_table_count' => null,
        'missing_tables_after' => $sourceMissingTables,
        'missing_columns_after' => $sourceMissingColumns,
        'migration_success' => empty($sourceMissingTables) && empty($sourceMissingColumns),
        'import_validation' => 'not run',
        'ota_missing' => [],
        'executed' => [],
        'errors' => [],
        'warnings' => [],
    ];

    if ($sourceMissingTables || $sourceMissingColumns) {
        $summary['errors'][] = 'SQL resources do not contain all code-required schema definitions.';
    }

    $runDatabase = (bool) ($options['migrate'] || $options['import'] || $options['validate-import']);
    $mysqlBin = $runDatabase ? find_mysql_bin(is_string($options['mysql-bin']) ? $options['mysql-bin'] : null) : '';

    if ($options['import'] && !$options['force-import']) {
        throw new RuntimeException('Refusing to import database/init_full.sql into target DB without --force-import because the base dump contains DROP TABLE statements.');
    }

    if ($options['validate-import']) {
        $tempDatabase = 'suxios_schema_check_' . getmypid() . '_' . bin2hex(random_bytes(6));
        $tempOptions = $options;
        $tempOptions['database'] = $tempDatabase;
        $tempDatabaseCreated = false;
        try {
            create_isolated_database($tempOptions);
            $tempDatabaseCreated = true;
            import_init_full($tempDatabase, $tempOptions, $mysqlBin);
            $tempPdo = pdo_database($tempOptions);
            $versionResult = register_baseline_and_apply_pending($tempPdo);
            $tempActual = inspect_database($tempPdo, $tempDatabase);
            $tempOta = summarize_ota_fields($tempActual['schema']);
            $importMissingTables = array_values(array_diff($requiredTables, array_keys($tempActual['schema'])));
            sort($importMissingTables);
            $importMissingColumns = missing_columns(
                $tempActual['schema'],
                $refs['columns'],
                $requiredTables
            );
            $summary['missing_tables_after'] = $importMissingTables;
            $summary['missing_columns_after'] = $importMissingColumns;
            if ($importMissingTables !== [] || $importMissingColumns !== []) {
                $summary['errors'][] = 'Fresh import is missing code-required tables or columns.';
                $summary['migration_success'] = false;
            }
            $summary['import_validation'] = 'passed, tables=' . count($tempActual['schema'])
                . ', ota_fields=' . $tempOta['count']
                . ', versions=' . (int)$versionResult['status']['applied_count'];
        } finally {
            if ($tempDatabaseCreated) {
                pdo_server($options)->exec('DROP DATABASE IF EXISTS ' . quote_identifier($tempDatabase));
            }
        }
    }

    if ($options['import']) {
        ensure_database($options);
        import_init_full((string) $options['database'], $options, $mysqlBin);
        $versionResult = register_baseline_and_apply_pending(pdo_database($options));
        $summary['executed'][] = 'SOURCE database/init_full.sql';
        $summary['executed'] = array_values(array_merge($summary['executed'], $versionResult['executed']));
    }

    if ($options['migrate']) {
        ensure_database($options);
        $pdo = pdo_database($options);
        $before = inspect_database($pdo, (string) $options['database']);
        $migration = execute_schema_migration($pdo, $resources, $before, (string) $options['database']);
        $summary['executed'] = array_values(array_merge($summary['executed'], $migration['executed']));
        $summary['errors'] = array_values(array_merge($summary['errors'], $migration['errors']));
        $actual = $migration['actual'];
        $summary['actual_table_count'] = count($actual['schema']);
        $summary['missing_tables_after'] = array_values(array_diff(array_keys($resources['schema']), array_keys($actual['schema'])));
        sort($summary['missing_tables_after']);
        $summary['missing_columns_after'] = missing_columns($actual['schema'], array_map(static fn(array $columns): array => array_fill_keys($columns, []), $resources['schema']), array_keys($resources['schema']));
        $summary['ota_missing'] = summarize_ota_fields($actual['schema'])['missing'];
        $summary['migration_success'] = empty($summary['errors']) && empty($summary['missing_tables_after']) && empty($summary['missing_columns_after']) && empty($summary['ota_missing']);
    } elseif ($runDatabase && !$options['import'] && !$options['validate-import']) {
        $pdo = pdo_database($options);
        $actual = inspect_database($pdo, (string) $options['database']);
        $summary['actual_table_count'] = count($actual['schema']);
        $summary['missing_tables_after'] = array_values(array_diff($requiredTables, array_keys($actual['schema'])));
        sort($summary['missing_tables_after']);
        $summary['missing_columns_after'] = missing_columns($actual['schema'], $refs['columns'], $requiredTables);
        $summary['ota_missing'] = summarize_ota_fields($actual['schema'])['missing'];
        $summary['migration_success'] = empty($summary['missing_tables_after']) && empty($summary['missing_columns_after']) && empty($summary['ota_missing']);
    }

    if (!$runDatabase) {
        $contractPassed = empty($sourceMissingTables) && empty($sourceMissingColumns);
        if ($options['json']) {
            echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
            exit($contractPassed ? 0 : 1);
        }

        echo ($contractPassed ? "OK: SQL schema contract passed\n" : "SQL schema contract failed\n");
        echo "Full SQL tables: " . count($resources['schema']) . "\n";
        echo "Code-required tables: " . count($requiredTables) . "\n";
        echo "Tracked baseline tables: " . count($baselineResources['schema']) . " ({$baselineSqlFile})\n";
        echo "Baseline gaps covered by migrations: " . count($baselineMissingTables) . " tables, " . count($baselineMissingColumns) . " columns\n";
        echo "Optional table refs skipped: " . implode(', ', $optionalTables) . "\n";
        if (!$contractPassed) {
            echo "Missing source SQL tables: " . ($sourceMissingTables ? implode(', ', $sourceMissingTables) : 'none') . "\n";
            echo "Missing source SQL columns: " . ($sourceMissingColumns ? implode(', ', $sourceMissingColumns) : 'none') . "\n";
        }
        exit($contractPassed ? 0 : 1);
    }

    if ($options['json']) {
        echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        $report = build_report($summary);
        echo $report;
        if (is_string($options['report']) && $options['report'] !== '') {
            global $root;
            $reportPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $options['report']);
            $dir = dirname($reportPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($reportPath, $report);
        }
    }

    exit($summary['migration_success'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "SQL schema contract failed\n" . $e->getMessage() . PHP_EOL);
    exit(1);
}
