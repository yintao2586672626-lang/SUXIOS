import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const support = require('../public/form-operation-support.js');

const failures = [];

function check(condition, message) {
  if (!condition) failures.push(message);
}

check(support.shouldPersistField({ name: 'hotel_name', type: 'text' }) === true, 'normal text fields should be persisted');
check(support.shouldPersistField({ name: 'password', type: 'password' }) === false, 'password fields must not be persisted');
check(support.shouldPersistField({ name: 'cookies', type: 'textarea' }) === false, 'cookie fields must not be persisted');
check(support.shouldPersistField({ name: 'api_key', type: 'text' }) === false, 'api key fields must not be persisted');

check(support.classifyOperation('/api/hotels', { method: 'POST' })?.type === 'save', 'POST form API should be classified as save');
check(support.classifyOperation('/api/users/12', { method: 'PUT' })?.type === 'save', 'PUT form API should be classified as save');
check(support.classifyOperation({ url: '/api/users/12', method: 'PATCH' })?.type === 'save', 'Request-like form API should use the request method');
check(support.classifyOperation('/api/opening/projects/8', { method: 'DELETE' })?.type === 'archive', 'DELETE form API should be classified as archive');
check(support.classifyOperation('/api/auth/login', { method: 'POST' }) === null, 'auth requests should be excluded from form history');

const storage = new Map();
const store = support.createStore({
  storage: {
    getItem: (key) => storage.get(key) ?? null,
    setItem: (key, value) => storage.set(key, value),
    removeItem: (key) => storage.delete(key),
  },
  now: () => 1710000000000,
});

store.recordHistory({ type: 'save', method: 'POST', url: '/api/hotels', ok: true });
store.recordHistory({ type: 'archive', method: 'DELETE', url: '/api/hotels/1', ok: true });

check(store.getHistory().length === 2, 'history should record save and archive operations');
check(store.getArchive().length === 1, 'archive history should include archive operations only');

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('Form operation support verification passed.');
