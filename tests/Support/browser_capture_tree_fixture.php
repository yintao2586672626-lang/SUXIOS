<?php
declare(strict_types=1);

if (($argv[1] ?? '') === 'child') {
    usleep(30_000_000);
    exit(0);
}

if (($argv[1] ?? '') !== 'root-with-child' || PHP_OS_FAMILY !== 'Windows') {
    exit(2);
}

$powershell = rtrim((string)(getenv('SystemRoot') ?: 'C:\\Windows'), '\\/')
    . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
$quote = static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'";
$script = 'Start-Process -FilePath ' . $quote(PHP_BINARY)
    . ' -ArgumentList @(' . $quote(__FILE__) . ',' . $quote('child') . ') -WindowStyle Hidden; '
    . 'Start-Sleep -Seconds 4';
$pipes = [];
$launcher = proc_open(
    [$powershell, '-NoProfile', '-NonInteractive', '-Command', $script],
    [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
    $pipes,
    __DIR__,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($launcher)) {
    exit(3);
}
$launcherExit = proc_close($launcher);
if ($launcherExit !== 0) {
    exit(4);
}

// Give the supervising runner multiple enumeration cycles to record the
// descendant identity before this root exits naturally.
usleep(750_000);
exit(0);
