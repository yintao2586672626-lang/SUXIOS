import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const file = 'public/index.html';
const source = fs.readFileSync(path.join(root, file), 'utf8');
const staticFile = 'public/expansion-static-options.js';
const staticSource = fs.readFileSync(path.join(root, staticFile), 'utf8');
const combinedSource = `${source}\n${staticSource}`;
const failures = [];

function requireText(needle, label) {
  if (!combinedSource.includes(needle)) {
    failures.push(`${file} and ${staticFile} missing ${label}: ${needle}`);
  }
}

function requireIndexText(needle, label) {
  if (!source.includes(needle)) {
    failures.push(`${file} missing ${label}: ${needle}`);
  }
}

function requireNoText(needle, label) {
  if (combinedSource.includes(needle)) {
    failures.push(`${file} or ${staticFile} contains forbidden ${label}: ${needle}`);
  }
}

requireText("湘潭: ['雨湖区', '岳塘区', '湘潭县'", 'Xiangtan district options start with real districts');
requireText("'湘乡市', '韶山市']", 'Xiangtan county-level city options');
requireText("安庆: ['迎江区', '大观区', '宜秀区', '怀宁县'", 'Anqing district options start with real districts');
requireText("'桐城市', '潜山市']", 'Anqing county-level city options');
requireText("strategyLocationSuffixesByCityTier", 'city-tier address suffix mapping');
requireIndexText("strategyAddressKeywordOptionsForLocation", 'location-aware address option builder');
requireIndexText("aiProject.value.city, aiProject.value.district", 'address options use city and district together');
requireIndexText("aiProject.value.city_tier", 'address options use city tier');
requireNoText("const strategyDistrictOptionsForCity = (city) => strategyDistrictOptionsByCity[city] || ['市辖区'];", 'generic district fallback for known city flow');

if (failures.length) {
  console.error('Strategy location UI contract verification failed:');
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log('Strategy location UI contract verification passed.');
