import { readFileSync } from 'node:fs';

const source = readFileSync('public/index.html', 'utf8');

const functionMatch = source.match(/const generateOtaDiagnosis = async \(\) => \{[\s\S]*?\n            \};/);
const generateBody = functionMatch ? functionMatch[0] : '';

const checks = [
  {
    name: 'diagnosis auto-fetch helper exists',
    pass: /const runOtaDiagnosisHotelFetch = async \(/.test(source),
  },
  {
    name: 'generate waits for auto-fetch before diagnosis API call',
    pass: generateBody.includes('await runOtaDiagnosisHotelFetch(selectedHotel, form)')
      && generateBody.indexOf('await runOtaDiagnosisHotelFetch(selectedHotel, form)') < generateBody.indexOf("request('/agent/ota-diagnosis'"),
  },
  {
    name: 'auto-fetch includes Ctrip business data',
    pass: source.includes("url: '/online-data/fetch-ctrip'"),
  },
  {
    name: 'auto-fetch includes Ctrip traffic data',
    pass: source.includes("url: '/online-data/ctrip/traffic'"),
  },
  {
    name: 'auto-fetch includes Meituan ranking data',
    pass: source.includes("url: '/online-data/fetch-meituan'"),
  },
  {
    name: 'auto-fetch includes Meituan traffic data',
    pass: source.includes("url: '/online-data/fetch-meituan-traffic'"),
  },
  {
    name: 'auto-fetch includes Ctrip comments data',
    pass: source.includes("url: '/online-data/fetch-ctrip-comments'"),
  },
  {
    name: 'auto-fetch includes Meituan comments data',
    pass: source.includes("url: '/online-data/fetch-meituan-comments'"),
  },
  {
    name: 'auto-fetch writes fetched rows to selected system hotel',
    pass: /system_hotel_id:\s*systemHotelId/.test(source) && /auto_save:\s*true/.test(source),
  },
];

const failed = checks.filter(check => !check.pass);
for (const check of checks) {
  console.log(`${check.pass ? 'PASS' : 'FAIL'} ${check.name}`);
}

if (failed.length > 0) {
  process.exit(1);
}
