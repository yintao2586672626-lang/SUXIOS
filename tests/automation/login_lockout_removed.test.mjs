import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');

test('system login no longer enforces failed-attempt lockout', () => {
  const authController = read('app/controller/Auth.php');
  const authMiddleware = read('app/middleware/Auth.php');
  const systemConfig = read('app/model/SystemConfig.php');
  const systemStatic = read('public/system-static.js');
  const indexHtml = read('public/index.html');

  assert.doesNotMatch(authController, /locked_until|recordLoginFailure|loginLockKey|login_max_attempts|login_lockout_duration/);
  assert.doesNotMatch(authMiddleware, /user_token_[\s\S]{0,160}(?:!==|!=)\s*\$token|cache\('token_'\s*\.\s*cache\('user_token_'/);
  assert.doesNotMatch(systemConfig, /KEY_LOGIN_MAX_ATTEMPTS|KEY_LOGIN_LOCKOUT_DURATION/);
  assert.doesNotMatch(systemStatic, /login_max_attempts|login_lockout_duration/);
  assert.doesNotMatch(indexHtml, /login_max_attempts|login_lockout_duration/);
});
