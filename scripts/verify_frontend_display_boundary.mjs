import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const publicIndex = readFileSync(join(root, 'public', 'index.html'), 'utf8');
const onlineDataConcernDir = join(root, 'app', 'controller', 'concern');
const onlineDataConcernFiles = existsSync(onlineDataConcernDir)
  ? readdirSync(onlineDataConcernDir)
    .filter(file => file.endsWith('.php'))
    .sort()
    .map(file => join(onlineDataConcernDir, file))
  : [];
const onlineDataControllerSource = [
  join(root, 'app', 'controller', 'OnlineData.php'),
  ...onlineDataConcernFiles,
].map(file => readFileSync(file, 'utf8')).join('\n');
const readBackendSource = (file) => (
  file === 'app/controller/OnlineData.php'
    ? onlineDataControllerSource
    : readFileSync(join(root, file), 'utf8')
);

const forbiddenFrontendTokens = [
  ['const extractAllCtripHotels', 'frontend must not parse Ctrip business response payloads'],
  ['data.peerRankData', 'frontend must not parse Meituan peerRankData'],
  ['roundRanks', 'frontend must not parse Meituan rank rows'],
  ['aiMetricName', 'frontend must not infer Meituan metric type from platform fields'],
  ['dimNameMap', 'frontend must not keep Meituan dimension mapping'],
  ['isSalesRoomNights', 'frontend must not classify Meituan sales room nights'],
  ['isRoomRevenueFINAL', 'frontend must not classify Meituan room revenue'],
  ['isExposureFINAL', 'frontend must not classify Meituan exposure metrics'],
  ['meituanHotelsList.value = allHotels.sort', 'frontend must consume backend Meituan display rows'],
  ['const normalizeCtripTrafficRows', 'frontend must not normalize Ctrip traffic payloads'],
  ['const buildCtripTrafficSummary', 'frontend must not calculate Ctrip traffic summary'],
  ['const normalizeCtripTrafficDateValue', 'frontend must not normalize Ctrip traffic dates'],
  ['const firstCtripTrafficValue', 'frontend must not map Ctrip traffic platform fields'],
  ['const normalizeCtripTrafficPercent', 'frontend must not normalize Ctrip traffic percentages'],
  ['circleAvg = ctripHotelsList.filter', 'frontend must not calculate Ctrip business ARI/SCI'],
  ['aVal = ctripBookingRate(a)', 'frontend must not sort Ctrip business rows by frontend booking-rate formulas'],
  ['const ctripBookingRate', 'frontend must not calculate Ctrip booking rate'],
  ['const formatCtripBookingRate', 'frontend must not format calculated Ctrip booking rate'],
  ['ctripHotelsList.reduce', 'frontend must not calculate Ctrip business summary metrics'],
  ['ctripHotelsList.filter', 'frontend must not filter Ctrip business rows for summary formulas'],
  ['revenueConcentration(ctripHotelsList', 'frontend must not calculate Ctrip revenue concentration'],
  ['visitConcentration(ctripHotelsList', 'frontend must not calculate Ctrip visit concentration'],
  ['circleAvgPrice', 'frontend must not calculate Ctrip price index formulas'],
  ['meituanHotelsList.reduce', 'frontend must not calculate Meituan business summary metrics'],
  ['meituanHotelsList.filter', 'frontend must not filter Meituan business rows for summary formulas'],
  ['revenueConcentration(meituanHotelsList', 'frontend must not calculate Meituan revenue concentration'],
  ['visitConcentration(meituanHotelsList', 'frontend must not calculate Meituan visit concentration'],
  ['calculateHhi(meituanHotelsList', 'frontend must not calculate Meituan HHI metrics'],
  ['priceRealizationRates', 'frontend must not calculate Meituan price realization formulas'],
  ['const mergeMeituanDisplayHotels', 'frontend must not merge Meituan platform rows'],
  ['aVal = (a.roomRevenue || 0) /', 'frontend must not sort Meituan rows by calculated room price'],
  ['aVal = (a.sales || 0) /', 'frontend must not sort Meituan rows by calculated sales price'],
  ['aVal = (a.views || 0) *', 'frontend must not sort Meituan rows by calculated order count'],
  ['aVal = (a.viewConversion || 0) *', 'frontend must not sort Meituan rows by calculated conversion'],
];

const requiredBackendTokens = [
  ['app/controller/OnlineData.php', 'buildCtripBusinessDisplayHotels', 'backend must build Ctrip business display rows'],
  ['app/controller/OnlineData.php', 'buildMeituanBusinessDisplayHotels', 'backend must build Meituan business display rows'],
  ['app/controller/OnlineData.php', 'buildCtripBusinessDisplaySummary', 'backend must build Ctrip business display summary'],
  ['app/controller/OnlineData.php', 'buildMeituanBusinessDisplaySummary', 'backend must build Meituan business display summary'],
  ['app/controller/OnlineData.php', 'mergeMeituanBusinessDisplayHotels', 'backend must merge Meituan display rows'],
  ['app/controller/OnlineData.php', 'meituanDisplayModel', 'backend must expose Meituan display model endpoint'],
  ['app/service/CtripTrafficDisplayService.php', 'buildCtripTrafficDisplayRows', 'backend service must build Ctrip traffic display rows'],
  ['app/service/CtripTrafficDisplayService.php', 'buildCtripTrafficDisplaySummary', 'backend service must build Ctrip traffic summary'],
];

const failures = [];

for (const [token, message] of forbiddenFrontendTokens) {
  if (publicIndex.includes(token)) {
    failures.push(`${message}: found ${token}`);
  }
}

for (const [file, token, message] of requiredBackendTokens) {
  const source = readBackendSource(file);
  if (!source.includes(token)) {
    failures.push(`${message}: missing ${token}`);
  }
}

if (failures.length > 0) {
  console.error('Frontend display boundary verification failed:');
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log('Frontend display boundary verification passed.');
