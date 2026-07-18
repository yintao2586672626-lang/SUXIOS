import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {}, setTimeout, clearTimeout };
vm.runInNewContext(fs.readFileSync('public/system-static.js', 'utf8'), context, {
  filename: 'public/system-static.js',
});

const {
  applyRememberedLoginAccount,
  getRememberedLoginAccount,
  saveLoginPasswordWithBrowser,
} = context.window.SUXI_SYSTEM_STATIC;

const createStorage = (entries = []) => {
  const values = new Map(entries);
  return {
    values,
    storage: {
      getItem: key => values.get(key) || '',
      setItem: (key, value) => values.set(key, String(value)),
      removeItem: key => values.delete(key),
    },
  };
};

test('password preference never stores plaintext and only reports remembered after browser save succeeds', () => {
  const { values, storage } = createStorage([
    ['remembered_username', 'manager01'],
    ['remembered_password', 'legacy-secret'],
  ]);

  const legacy = getRememberedLoginAccount(storage);
  assert.equal(legacy.username, 'manager01');
  assert.equal(legacy.remember, false);
  assert.equal(legacy.form.password, '');
  assert.equal(values.has('remembered_password'), false);

  applyRememberedLoginAccount({ storage, username: 'manager01', remember: true });
  const remembered = getRememberedLoginAccount(storage);
  assert.equal(remembered.remember, true);
  assert.equal(values.get('remembered_username'), 'manager01');
  assert.equal(values.get('suxios_browser_password_save_v1'), '1');
  assert.equal(values.has('remembered_password'), false);

  applyRememberedLoginAccount({ storage, username: 'manager01', remember: false });
  assert.equal(values.has('remembered_username'), false);
  assert.equal(values.has('suxios_browser_password_save_v1'), false);
});

test('browser credential helper stores the credential through PasswordCredential without leaking it in the result', async () => {
  let storedCredential = null;
  class TestPasswordCredential {
    constructor(data) {
      Object.assign(this, data);
    }
  }

  const result = await saveLoginPasswordWithBrowser({
    username: ' manager01 ',
    password: 'secret123',
    remember: true,
    PasswordCredentialCtor: TestPasswordCredential,
    credentialStore: { store: async credential => { storedCredential = credential; } },
  });

  assert.equal(result.status, 'saved');
  assert.equal(result.level, 'success');
  assert.equal(Object.hasOwn(result, 'password'), false);
  assert.equal(storedCredential.id, 'manager01');
  assert.equal(storedCredential.name, 'manager01');
  assert.equal(storedCredential.password, 'secret123');
});

test('unsupported or declined browser saves stay truthful and never block login', async () => {
  const unsupported = await saveLoginPasswordWithBrowser({
    username: 'manager01',
    password: 'secret123',
    remember: true,
    credentialStore: null,
    PasswordCredentialCtor: null,
  });
  assert.equal(unsupported.status, 'unsupported');
  assert.match(unsupported.message, /浏览器密码管理器/);

  class TestPasswordCredential {
    constructor(data) {
      Object.assign(this, data);
    }
  }
  const declined = await saveLoginPasswordWithBrowser({
    username: 'manager01',
    password: 'secret123',
    remember: true,
    PasswordCredentialCtor: TestPasswordCredential,
    credentialStore: {
      store: async () => {
        const error = new Error('user declined');
        error.name = 'NotAllowedError';
        throw error;
      },
    },
  });
  assert.equal(declined.status, 'declined');
  assert.equal(declined.level, 'warning');

  const skipped = await saveLoginPasswordWithBrowser({ remember: false });
  assert.equal(skipped.status, 'not_requested');
  assert.equal(skipped.message, '');
});

test('a browser credential prompt that never settles times out truthfully', async () => {
  class TestPasswordCredential {
    constructor(data) {
      Object.assign(this, data);
    }
  }
  const startedAt = Date.now();
  const result = await saveLoginPasswordWithBrowser({
    username: 'manager01',
    password: 'secret123',
    remember: true,
    timeoutMs: 25,
    PasswordCredentialCtor: TestPasswordCredential,
    credentialStore: { store: () => new Promise(() => {}) },
  });

  assert.equal(result.status, 'timeout');
  assert.equal(result.level, 'warning');
  assert.match(result.message, /登录不受影响/);
  assert(Date.now() - startedAt < 250, 'hung credential storage must settle through its timeout');
});

test('blocked site storage cannot break the login flow', () => {
  const blockedStorage = {
    getItem: () => { throw new DOMException('blocked', 'SecurityError'); },
    setItem: () => { throw new DOMException('blocked', 'SecurityError'); },
    removeItem: () => { throw new DOMException('blocked', 'SecurityError'); },
  };

  assert.doesNotThrow(() => applyRememberedLoginAccount({
    storage: blockedStorage,
    username: 'manager01',
    remember: true,
  }));
  const remembered = getRememberedLoginAccount(blockedStorage);
  assert.equal(remembered.username, '');
  assert.equal(remembered.remember, false);
  assert.equal(remembered.form.password, '');
});

test('both login paths clear the submitted password without awaiting browser storage', () => {
  const bootstrap = fs.readFileSync('public/app-bootstrap.js', 'utf8');
  const appMain = fs.readFileSync('public/app-main.js', 'utf8');

  assert.match(bootstrap, /const passwordSavePromise = saveLoginPasswordWithBrowser\([\s\S]*?password\.value = '';/);
  assert.match(appMain, /const passwordSavePromise = saveLoginPasswordWithBrowser\([\s\S]*?loginForm\.value\.password = '';/);
  assert.doesNotMatch(bootstrap, /await saveLoginPasswordWithBrowser\(/);
  assert.doesNotMatch(appMain, /await saveLoginPasswordWithBrowser\(/);
});
