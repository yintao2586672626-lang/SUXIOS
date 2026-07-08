import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const args = process.argv.slice(2);

if (args.length === 0) {
  console.error('Usage: node scripts/run_php.mjs <script.php> [...args]');
  process.exit(2);
}

const candidates = [
  process.env.SUXI_PHP,
  process.env.PHP_BINARY,
  'C:\\xampp\\php\\php.exe',
  'D:\\xampp\\php\\php.exe',
  'C:\\php\\php.exe',
  'php',
].filter(Boolean);

const canRun = (candidate) => {
  if (candidate.includes('\\') || candidate.includes('/')) {
    return existsSync(candidate);
  }
  const result = spawnSync(candidate, ['-v'], { stdio: 'ignore' });
  return !result.error && result.status === 0;
};

const php = candidates.find(canRun);

if (!php) {
  console.error('PHP executable not found. Set SUXI_PHP or install PHP in PATH.');
  process.exit(127);
}

const result = spawnSync(php, args, { stdio: 'inherit' });

if (result.error) {
  console.error(result.error.message);
  process.exit(1);
}

process.exit(result.status ?? 1);
