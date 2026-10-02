import { readFileSync } from 'node:fs';
import vm from 'node:vm';

export function loadSystemStaticApi() {
  const context = { window: {}, console, URLSearchParams, Intl };
  vm.runInNewContext(readFileSync(new URL('../../../public/system-static.js', import.meta.url), 'utf8'), context);
  vm.runInNewContext(readFileSync(new URL('../../../public/system-page-projections.js', import.meta.url), 'utf8'), context);
  return context.window.SUXI_SYSTEM_STATIC;
}
