<?php
declare(strict_types=1);

// SQL is inherited directly by the controlled encryption parent's pipe, never read here.
if (PHP_SAPI !== 'cli') exit(1);
try {
    $mode = $argv[1] ?? '';
    if ($mode === '--stream' || $mode === '--fixture') {
        $stat = fstat(STDOUT);
        $controlled = getenv('SUXIOS_LOCAL_BACKUP_CONTROLLED_STREAM') ?: '';
        $target = getenv('SUXIOS_LOCAL_BACKUP_TARGET') ?: '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $controlled) || !str_ends_with($target, '.sql.enc')
            || !is_array($stat) || (($stat['mode'] & 0170000) !== 0010000) || stream_isatty(STDOUT)) {
            throw new RuntimeException('local_backup_stream_not_authorized');
        }
        foreach (['SUXIOS_LOCAL_BACKUP_KEY', 'SUXIOS_LOCAL_BACKUP_EXPECTED_SHA256', 'SUXIOS_LOCAL_BACKUP_CONTROLLED_STREAM'] as $name) putenv($name);
        if ($mode === '--fixture') {
            $fixture = $argv[2] ?? '';
            if (!in_array($fixture, ['success', 'stall', 'child_stall', 'heartbeat', 'stderr_failure'], true)) throw new RuntimeException('local_backup_fixture_invalid');
            if ($fixture === 'child_stall') {
                $child = proc_open([PHP_BINARY, '-r', 'fwrite(STDOUT,str_repeat("x",32));usleep(5000000);'],
                    [0 => ['file', 'NUL', 'r'], 1 => STDOUT, 2 => ['file', 'NUL', 'w']], $pipes, null, null, ['bypass_shell' => true]);
                if (!is_resource($child)) throw new RuntimeException('local_backup_fixture_process_failed');
                $identity = proc_get_status($child);
                fwrite(STDERR, 'SUXIOS-LOCAL-BACKUP-CONTEXT:' . json_encode(['database' => 'synthetic_fixture', 'producer_pid' => (int) $identity['pid']], JSON_THROW_ON_ERROR) . "\n");
                $closed = proc_close($child);
                exit(!$identity['running'] && $identity['exitcode'] >= 0 ? $identity['exitcode'] : $closed);
            }
            fwrite(STDERR, 'SUXIOS-LOCAL-BACKUP-CONTEXT:' . json_encode(['database' => 'synthetic_fixture', 'producer_pid' => 0], JSON_THROW_ON_ERROR) . "\n");
            if ($fixture === 'success') {
                for ($i = 0; $i < 50; $i++) fwrite(STDOUT, str_repeat("fixture-row-0123456789\n", 1000));
            } elseif ($fixture === 'stall') {
                fwrite(STDOUT, str_repeat('x', 32)); usleep(5000000);
            } elseif ($fixture === 'heartbeat') {
                for ($i = 0; $i < 25; $i++) { fwrite(STDOUT, str_repeat('x', 32)); usleep(200000); }
            } else {
                fwrite(STDOUT, str_repeat('x', 262144));
                for ($i = 0; $i < 512; $i++) fwrite(STDERR, str_repeat('synthetic-private-error', 512));
                exit(23);
            }
            exit(0);
        }
        require dirname(__DIR__) . '/vendor/autoload.php';
        (new think\App(dirname(__DIR__)))->initialize();
        $config = (array) config('database.connections.' . config('database.default', 'mysql'));
        if (!in_array($config['hostname'] ?? '', ['127.0.0.1', 'localhost'], true) || ($config['type'] ?? '') !== 'mysql') throw new RuntimeException('local_backup_config_invalid');
        $dump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        if (!is_file($dump)) throw new RuntimeException('local_backup_runtime_unavailable');
        $env = getenv(); $env['MYSQL_PWD'] = (string) ($config['password'] ?? '');
        foreach (array_keys($env) as $name) if (str_starts_with($name, 'SUXIOS_LOCAL_BACKUP_')) unset($env[$name]);
        $process = proc_open([
            $dump, '--host=' . $config['hostname'], '--port=' . (string) ($config['hostport'] ?? '3306'),
            '--user=' . (string) $config['username'], '--single-transaction', '--routines', '--events',
            '--triggers', '--hex-blob', '--skip-lock-tables', (string) $config['database'],
        ], [0 => ['file', 'NUL', 'r'], 1 => STDOUT, 2 => ['file', 'NUL', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        unset($env);
        if (!is_resource($process)) throw new RuntimeException('local_backup_process_unavailable');
        $identity = proc_get_status($process);
        fwrite(STDERR, 'SUXIOS-LOCAL-BACKUP-CONTEXT:' . json_encode(['database' => (string) $config['database'], 'producer_pid' => (int) $identity['pid']], JSON_THROW_ON_ERROR) . "\n");
        unset($config);
        $closed = proc_close($process);
        exit(!$identity['running'] && $identity['exitcode'] >= 0 ? $identity['exitcode'] : $closed);
    }
    // Preserve the original target/key environment and safe JSON API for direct CLI callers.
    $target = getenv('SUXIOS_LOCAL_BACKUP_TARGET') ?: '';
    $keyHex = getenv('SUXIOS_LOCAL_BACKUP_KEY') ?: '';
    if (!preg_match('/^[a-fA-F0-9]{64}$/D', $keyHex) || !str_ends_with($target, '.sql.enc') || file_exists($target)) throw new RuntimeException('local_backup_parameters_invalid');
    unset($keyHex);
    $requestedPwsh = getenv('SUXIOS_LOCAL_BACKUP_PWSH') ?: 'pwsh.exe';
    $pwsh = '';
    $candidates = $requestedPwsh === 'pwsh.exe'
        ? array_map(static fn (string $path): string => trim($path, '"') . DIRECTORY_SEPARATOR . 'pwsh.exe', explode(PATH_SEPARATOR, getenv('PATH') ?: ''))
        : [$requestedPwsh];
    foreach ($candidates as $candidate) {
        if (strtolower(basename($candidate)) === 'pwsh.exe' && is_file($candidate)) { $pwsh = realpath($candidate) ?: ''; break; }
    }
    if ($pwsh === '') throw new RuntimeException('local_backup_runtime_unavailable');
    $process = proc_open([$pwsh, '-NoLogo', '-NoProfile', '-NonInteractive', '-File', __DIR__ . '/lib/local_database_encrypted_backup.ps1'],
        [0 => ['file', 'NUL', 'r'], 1 => STDOUT, 2 => ['file', 'NUL', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('local_backup_runtime_unavailable');
    putenv('SUXIOS_LOCAL_BACKUP_KEY');
    exit(proc_close($process));
} catch (Throwable $error) {
    fwrite(STDERR, "local_encrypted_backup_failed\n");
    exit(1);
}
