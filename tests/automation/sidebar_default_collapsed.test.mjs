import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const html = readFileSync('public/index.html', 'utf8');
const systemStatic = readFileSync('public/system-static.js', 'utf8');

test('sidebar defaults to expanded on desktop and remembers manual toggle', () => {
  assert.match(systemStatic, /const sidebarPreferenceKey = 'suxios_sidebar_collapsed'/);
  assert.match(systemStatic, /const loadSidebarCollapsedPreference = \(storage = browserLocalStorage\(\)\) => \{/);
  assert.match(systemStatic, /if \(saved === 'expanded'\) return false;\s*\n\s*if \(saved === 'collapsed'\) return true;/);
  assert.match(systemStatic, /return false;\s*\n\s*\};\s*\n\s*const persistSidebarCollapsedPreference/);
  assert.match(systemStatic, /storage\?\.setItem\?\.\(sidebarPreferenceKey, collapsed \? 'collapsed' : 'expanded'\)/);

  assert.match(html, /const loadSidebarCollapsedPreference = requireAppSystemStatic\('loadSidebarCollapsedPreference'\)/);
  assert.match(html, /const persistSidebarCollapsedPreferenceStatic = requireAppSystemStatic\('persistSidebarCollapsedPreference'\)/);
  assert.match(html, /persistSidebarCollapsedPreferenceStatic\(sidebarCollapsed\.value, localStorage\)/);
  assert.match(html, /const sidebarCollapsed = ref\(loadSidebarCollapsedPreference\(localStorage\)\)/);
  assert.match(html, /sidebarCollapsed\.value = !sidebarCollapsed\.value;\s*\n\s*persistSidebarCollapsedPreference\(\);/);
});
