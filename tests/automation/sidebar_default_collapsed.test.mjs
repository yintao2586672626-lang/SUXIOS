import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const html = readFileSync('public/index.html', 'utf8');

test('sidebar defaults to expanded on desktop and remembers manual toggle', () => {
  assert.match(html, /const sidebarPreferenceKey = 'suxios_sidebar_collapsed'/);
  assert.match(html, /const loadSidebarCollapsedPreference = \(\) => \{/);
  assert.match(html, /return false;\s*\n\s*\};\s*\n\s*const persistSidebarCollapsedPreference/);
  assert.match(html, /localStorage\.setItem\(sidebarPreferenceKey, sidebarCollapsed\.value \? 'collapsed' : 'expanded'\)/);
  assert.match(html, /const sidebarCollapsed = ref\(loadSidebarCollapsedPreference\(\)\)/);
  assert.match(html, /persistSidebarCollapsedPreference\(\);/);
});
