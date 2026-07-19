import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');

test('system login avoids persistent account lockout but enforces bounded request throttling', () => {
  const authController = read('app/controller/Auth.php');
  const loginRateLimiter = read('app/service/LoginRateLimiter.php');
  const limiterMigration = read('database/migrations/20260719_create_login_rate_limit_counters.sql');
  const authMiddleware = read('app/middleware/Auth.php');
  const systemConfig = read('app/model/SystemConfig.php');
  const systemStatic = read('public/system-static.js');
  const indexHtml = read('public/index.html');

  assert.doesNotMatch(authController, /locked_until|loginLockKey|login_max_attempts|login_lockout_duration/);
  assert.doesNotMatch(authMiddleware, /user_token_[\s\S]{0,160}(?:!==|!=)\s*\$token|cache\('token_'\s*\.\s*cache\('user_token_'/);
  assert.doesNotMatch(systemConfig, /KEY_LOGIN_MAX_ATTEMPTS|KEY_LOGIN_LOCKOUT_DURATION/);
  assert.doesNotMatch(systemStatic, /login_max_attempts|login_lockout_duration/);
  assert.doesNotMatch(indexHtml, /login_max_attempts|login_lockout_duration/);
  assert.match(authController, /new LoginRateLimiter\(\)[\s\S]*consumeAttempt\(\$ip, \$username\)/);
  assert.match(authController, /login_rate_limited[\s\S]*Retry-After/);
  assert.match(authController, /reservation_bucket[\s\S]*releaseLoginRateLimitReservation[\s\S]*releaseSuccessfulAttempt\(\$ip, \$username, \$reservationBucket\)/);
  assert.match(loginRateLimiter, /IDENTITY_LIMIT = 10[\s\S]*USERNAME_LIMIT = 25[\s\S]*IP_LIMIT = 40/);
  assert.match(loginRateLimiter, /private const TABLE = 'login_rate_limit_counters'/);
  assert.match(loginRateLimiter, /databaseConsumeAttempt[\s\S]*Db::transaction[\s\S]*databaseEnsureRowsLocked/);
  assert.match(loginRateLimiter, /databaseRelease[\s\S]*attempt_count` = `attempt_count` - 1/);
  assert.match(loginRateLimiter, /custom store[\s\S]*synchronized[\s\S]*flock\(\$handle, LOCK_EX\)/);
  assert.match(limiterMigration, /CREATE TABLE IF NOT EXISTS `login_rate_limit_counters`[\s\S]*ENGINE=InnoDB/);
  assert.match(loginRateLimiter, /hash\('sha256', \$normalizedIp\)[\s\S]*hash\('sha256', \$normalizedUsername\)[\s\S]*hash\('sha256', \$normalizedIp/);
  assert.doesNotMatch(loginRateLimiter, /locked_until|login_lockout_duration/);
});
