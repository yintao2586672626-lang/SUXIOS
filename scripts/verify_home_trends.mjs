import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();

const files = {
  route: readFileSync(join(root, 'route', 'app.php'), 'utf8'),
  controller: readFileSync(join(root, 'app', 'controller', 'MacroSignal.php'), 'utf8'),
  service: readFileSync(join(root, 'app', 'service', 'MacroSignalService.php'), 'utf8'),
  html: readFileSync(join(root, 'public', 'index.html'), 'utf8'),
};

const checks = [
  ['route exposes macro trend endpoint', files.route.includes("Route::get('/trends', 'MacroSignal/trends')")],
  ['controller has trends action', /function\s+trends\s*\(/.test(files.controller)],
  ['service has trendOverview method', /function\s+trendOverview\s*\(/.test(files.service)],
  ['front-end stores home trend payload', files.html.includes('const homeTrendData = ref(')],
  ['front-end has range selector', files.html.includes('selectHomeTrendRange')],
  ['front-end has metric selector', files.html.includes('selectHomeTrendMetric')],
  ['front-end renders trend chart', files.html.includes('renderHomeTrendChart')],
  ['front-end calls trend API', files.html.includes('/macro-signals/trends?')],
  ['front-end labels aggregate hotel scope', files.html.includes("hotelName: selectedHotel?.name || '全部门店'")],
];

const failed = checks.filter(([, ok]) => !ok);
if (failed.length > 0) {
  console.error('Home trend verification failed:');
  for (const [name] of failed) {
    console.error(`- ${name}`);
  }
  process.exit(1);
}

console.log('Home trend verification passed.');
