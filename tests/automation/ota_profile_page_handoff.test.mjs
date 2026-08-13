import assert from 'node:assert/strict';
import test from 'node:test';
import {
  liveProfilePageCandidates,
  resolveProfileLoginPage,
} from '../../scripts/lib/ota_profile_page_handoff.mjs';

const fakePage = (url, { closed = false, loggedIn = false } = {}) => ({
  isClosed: () => closed,
  url: () => url,
  loggedIn,
});

test('Profile login follows a newly opened trusted page when the initial page closes', async () => {
  const initial = fakePage('https://ebooking.ctrip.com/login', { closed: true });
  const replacement = fakePage('https://ebooking.ctrip.com/home/mainland', { loggedIn: true });
  const context = { pages: () => [initial, replacement] };

  const resolved = await resolveProfileLoginPage(
    context,
    initial,
    async page => page.loggedIn === true,
    url => url.startsWith('https://ebooking.ctrip.com/'),
  );

  assert.equal(resolved.page, replacement);
  assert.equal(resolved.loggedIn, true);
});

test('Profile login inspects a newer trusted page after a still-open login page', async () => {
  const initial = fakePage('https://ebooking.ctrip.com/login');
  const replacement = fakePage('https://ebooking.ctrip.com/home/mainland', { loggedIn: true });
  const unrelated = fakePage('about:blank', { loggedIn: true });
  const context = { pages: () => [initial, replacement, unrelated] };

  const candidates = liveProfilePageCandidates(
    context,
    initial,
    url => url.startsWith('https://ebooking.ctrip.com/'),
  );
  assert.deepEqual(candidates, [initial, replacement, unrelated]);

  const resolved = await resolveProfileLoginPage(
    context,
    initial,
    async page => page.loggedIn === true,
    url => url.startsWith('https://ebooking.ctrip.com/'),
  );
  assert.equal(resolved.page, replacement);
  assert.equal(resolved.loggedIn, true);
});

test('Profile login returns an explicit missing page instead of touching a closed page', async () => {
  const closed = fakePage('https://ebooking.ctrip.com/login', { closed: true });
  const resolved = await resolveProfileLoginPage(
    { pages: () => [closed] },
    closed,
    async () => false,
    () => true,
  );

  assert.equal(resolved.page, null);
  assert.equal(resolved.loggedIn, false);
});
