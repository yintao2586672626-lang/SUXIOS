import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const sampleSize = Number.parseInt(process.env.SUXI_MARKET_SAMPLE_SIZE || process.argv[2] || '1000', 10);
const seed = Number.parseInt(process.env.SUXI_MARKET_SAMPLE_SEED || '20260517', 10) >>> 0;
const dataSourceMode = (process.env.SUXI_MARKET_DATA_SOURCE || 'network').toLowerCase();
const requireNetwork = process.env.SUXI_MARKET_REQUIRE_NETWORK === '1';
const overpassEndpoint = process.env.SUXI_MARKET_OVERPASS_URL || 'https://overpass-api.de/api/interpreter';
const overpassPerCityLimit = Number.parseInt(process.env.SUXI_MARKET_OVERPASS_LIMIT || '20', 10);
const overpassTimeoutMs = Number.parseInt(process.env.SUXI_MARKET_OVERPASS_TIMEOUT_MS || '70000', 10);
const overpassBatchSize = Number.parseInt(process.env.SUXI_MARKET_OVERPASS_BATCH_SIZE || '12', 10);
const minimumCoveredCityCount = Number.parseInt(process.env.SUXI_MARKET_MIN_CITY_COVERAGE || '70', 10);

if (!Number.isInteger(sampleSize) || sampleSize <= 0) {
  console.error('SUXI_MARKET_SAMPLE_SIZE must be a positive integer.');
  process.exit(1);
}

const phpCandidates = [
  process.env.SUXI_PHP,
  'C:\\xampp\\php\\php.exe',
  'php',
].filter(Boolean);

const php = phpCandidates.find((candidate) => candidate === 'php' || existsSync(candidate));
if (!php) {
  console.error('PHP executable not found. Set SUXI_PHP or install PHP in PATH.');
  process.exit(1);
}

function createRng(initialSeed) {
  let state = initialSeed || 1;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

const rng = createRng(seed);
const pick = (items) => items[Math.floor(rng() * items.length)];
const int = (min, max) => Math.floor(rng() * (max - min + 1)) + min;
const money = (value) => Math.round(value / 100) * 100;

const majorDomesticCities = [
  { tier: '一线', city: '北京', bbox: [39.45, 115.42, 41.06, 117.51], rent: [1800, 4800], areas: ['国贸', '望京', '中关村', '北京南站', '首都机场'] },
  { tier: '一线', city: '上海', bbox: [30.67, 120.85, 31.87, 122.12], rent: [1900, 5000], areas: ['陆家嘴', '徐家汇', '虹桥枢纽', '人民广场', '张江'] },
  { tier: '一线', city: '广州', bbox: [22.94, 112.95, 23.56, 113.75], rent: [1300, 3800], areas: ['天河', '珠江新城', '琶洲', '广州南站', '白云机场'] },
  { tier: '一线', city: '深圳', bbox: [22.35, 113.75, 22.86, 114.63], rent: [1700, 4700], areas: ['福田CBD', '南山科技园', '罗湖口岸', '深圳北站', '宝安机场'] },
  { tier: '二线', city: '成都', bbox: [30.25, 103.60, 31.05, 104.55], rent: [900, 3000], areas: ['春熙路', '天府新区', '成都东站', '双流机场', '高新区'] },
  { tier: '二线', city: '重庆', bbox: [29.20, 106.15, 30.05, 107.05], rent: [800, 2800], areas: ['解放碑', '观音桥', '重庆北站', '江北机场', '沙坪坝'] },
  { tier: '二线', city: '杭州', bbox: [29.85, 119.75, 30.58, 120.72], rent: [1200, 3600], areas: ['武林广场', '钱江新城', '西湖景区', '滨江', '杭州东站'] },
  { tier: '二线', city: '武汉', bbox: [29.95, 113.70, 31.35, 115.10], rent: [850, 2800], areas: ['江汉路', '光谷', '汉口站', '武汉站', '武昌'] },
  { tier: '二线', city: '西安', bbox: [33.95, 108.50, 34.65, 109.50], rent: [800, 2600], areas: ['钟楼', '曲江', '高新区', '西安北站', '大雁塔'] },
  { tier: '二线', city: '苏州', bbox: [30.85, 120.15, 31.70, 121.05], rent: [900, 3000], areas: ['工业园区', '观前街', '苏州站', '金鸡湖', '高新区'] },
  { tier: '二线', city: '南京', bbox: [31.70, 118.35, 32.35, 119.20], rent: [1000, 3200], areas: ['新街口', '河西', '南京南站', '夫子庙', '江宁大学城'] },
  { tier: '二线', city: '天津', bbox: [38.70, 116.75, 39.45, 117.85], rent: [900, 3000], areas: ['滨江道', '天津站', '滨海新区', '梅江会展', '意式风情区'] },
  { tier: '二线', city: '郑州', bbox: [34.45, 113.20, 35.05, 114.05], rent: [750, 2500], areas: ['二七广场', '郑东新区', '郑州东站', '会展中心', '新郑机场'] },
  { tier: '二线', city: '长沙', bbox: [27.75, 112.55, 28.55, 113.35], rent: [800, 2600], areas: ['五一广场', '梅溪湖', '高铁南站', '岳麓山', '黄花机场'] },
  { tier: '二线', city: '东莞', bbox: [22.65, 113.50, 23.20, 114.25], rent: [850, 2800], areas: ['南城', '松山湖', '东莞站', '虎门', '厚街'] },
  { tier: '二线', city: '佛山', bbox: [22.65, 112.55, 23.50, 113.55], rent: [800, 2600], areas: ['祖庙', '千灯湖', '佛山西站', '顺德', '南海'] },
  { tier: '二线', city: '宁波', bbox: [29.55, 121.15, 30.10, 122.05], rent: [850, 2800], areas: ['天一广场', '东部新城', '宁波站', '鄞州', '栎社机场'] },
  { tier: '二线', city: '青岛', bbox: [35.80, 119.60, 36.45, 120.75], rent: [850, 2800], areas: ['五四广场', '青岛站', '崂山', '台东', '胶东机场'] },
  { tier: '二线', city: '沈阳', bbox: [41.45, 123.05, 42.15, 123.85], rent: [750, 2400], areas: ['中街', '太原街', '沈阳站', '浑南', '桃仙机场'] },
  { tier: '二线', city: '济南', bbox: [36.35, 116.55, 37.05, 117.45], rent: [750, 2400], areas: ['泉城广场', '济南西站', '高新区', '历下', '遥墙机场'] },
  { tier: '二线', city: '合肥', bbox: [31.45, 116.85, 32.25, 117.65], rent: [750, 2400], areas: ['政务区', '滨湖新区', '合肥南站', '包河', '高新区'] },
  { tier: '二线', city: '福州', bbox: [25.80, 119.05, 26.35, 119.65], rent: [750, 2500], areas: ['东街口', '台江', '福州站', '仓山', '长乐机场'] },
  { tier: '二线', city: '厦门', bbox: [24.35, 117.90, 24.75, 118.35], rent: [1000, 3200], areas: ['中山路', '会展中心', '厦门北站', '湖里', '鼓浪屿'] },
  { tier: '二线', city: '昆明', bbox: [24.65, 102.45, 25.35, 103.10], rent: [700, 2400], areas: ['南屏街', '滇池', '昆明站', '呈贡', '长水机场'] },
  { tier: '二线', city: '无锡', bbox: [31.20, 120.00, 31.80, 120.65], rent: [800, 2600], areas: ['三阳广场', '太湖新城', '无锡站', '滨湖', '硕放机场'] },
  { tier: '二线', city: '大连', bbox: [38.65, 121.20, 39.20, 122.05], rent: [800, 2600], areas: ['青泥洼桥', '星海广场', '大连北站', '中山', '周水子机场'] },
  { tier: '二线', city: '哈尔滨', bbox: [45.45, 126.20, 46.00, 127.10], rent: [700, 2300], areas: ['中央大街', '哈西站', '松北', '道里', '太平机场'] },
  { tier: '二线', city: '长春', bbox: [43.65, 125.00, 44.15, 125.75], rent: [650, 2200], areas: ['重庆路', '长春站', '净月', '红旗街', '龙嘉机场'] },
  { tier: '二线', city: '南昌', bbox: [28.35, 115.55, 29.05, 116.20], rent: [650, 2200], areas: ['八一广场', '红谷滩', '南昌西站', '青山湖', '昌北机场'] },
  { tier: '二线', city: '贵阳', bbox: [26.35, 106.45, 26.90, 107.05], rent: [650, 2200], areas: ['喷水池', '观山湖', '贵阳北站', '花溪', '龙洞堡机场'] },
  { tier: '二线', city: '南宁', bbox: [22.55, 108.05, 23.15, 108.75], rent: [650, 2200], areas: ['朝阳广场', '东盟商务区', '南宁东站', '青秀', '吴圩机场'] },
  { tier: '二线', city: '太原', bbox: [37.55, 112.25, 38.15, 112.90], rent: [650, 2200], areas: ['柳巷', '长风商务区', '太原南站', '小店', '武宿机场'] },
  { tier: '二线', city: '石家庄', bbox: [37.75, 114.20, 38.25, 114.80], rent: [650, 2200], areas: ['北国商城', '石家庄站', '裕华', '高新区', '正定机场'] },
  { tier: '二线', city: '常州', bbox: [31.50, 119.65, 32.05, 120.25], rent: [700, 2300], areas: ['南大街', '常州北站', '武进', '新北', '奔牛机场'] },
  { tier: '二线', city: '温州', bbox: [27.75, 120.35, 28.15, 120.85], rent: [750, 2500], areas: ['五马街', '温州南站', '鹿城', '瓯海', '龙湾机场'] },
  { tier: '二线', city: '泉州', bbox: [24.65, 118.35, 25.20, 119.00], rent: [650, 2200], areas: ['西街', '泉州站', '丰泽', '晋江', '晋江机场'] },
  { tier: '二线', city: '嘉兴', bbox: [30.55, 120.45, 31.00, 121.10], rent: [700, 2300], areas: ['南湖', '嘉兴南站', '秀洲', '月河', '经开区'] },
  { tier: '二线', city: '南通', bbox: [31.75, 120.65, 32.25, 121.25], rent: [700, 2300], areas: ['南大街', '南通站', '崇川', '通州', '兴东机场'] },
  { tier: '三线', city: '徐州', bbox: [34.05, 117.00, 34.55, 117.55], rent: [600, 2000], areas: ['云龙湖', '徐州东站', '彭城广场', '泉山', '新城区'] },
  { tier: '三线', city: '扬州', bbox: [32.15, 119.20, 32.65, 119.75], rent: [600, 2000], areas: ['东关街', '扬州站', '瘦西湖', '广陵', '邗江'] },
  { tier: '三线', city: '绍兴', bbox: [29.75, 120.35, 30.25, 120.85], rent: [650, 2100], areas: ['鲁迅故里', '绍兴北站', '越城', '柯桥', '镜湖'] },
  { tier: '三线', city: '台州', bbox: [28.35, 120.85, 28.95, 121.75], rent: [600, 2000], areas: ['椒江', '台州站', '黄岩', '路桥', '温岭'] },
  { tier: '三线', city: '金华', bbox: [28.85, 119.35, 29.45, 120.20], rent: [600, 2000], areas: ['江北商圈', '金华站', '婺城', '金东', '义乌商贸'] },
  { tier: '三线', city: '烟台', bbox: [37.25, 121.00, 37.75, 121.75], rent: [600, 2000], areas: ['芝罘', '烟台站', '莱山', '开发区', '蓬莱机场'] },
  { tier: '三线', city: '潍坊', bbox: [36.45, 118.75, 37.05, 119.35], rent: [550, 1900], areas: ['泰华城', '潍坊站', '奎文', '高新区', '寒亭'] },
  { tier: '三线', city: '洛阳', bbox: [34.35, 112.20, 34.85, 112.75], rent: [550, 1900], areas: ['王府井', '洛阳龙门站', '老城', '牡丹广场', '龙门石窟'] },
  { tier: '三线', city: '唐山', bbox: [39.35, 117.85, 39.95, 118.45], rent: [550, 1900], areas: ['万达广场', '唐山站', '路北', '高新区', '南湖'] },
  { tier: '三线', city: '保定', bbox: [38.60, 115.20, 39.10, 115.80], rent: [550, 1800], areas: ['裕华路', '保定东站', '竞秀', '莲池', '高新区'] },
  { tier: '三线', city: '珠海', bbox: [21.95, 113.20, 22.45, 113.70], rent: [750, 2500], areas: ['拱北口岸', '吉大', '珠海站', '横琴', '香洲'] },
  { tier: '三线', city: '中山', bbox: [22.20, 113.10, 22.75, 113.65], rent: [600, 2000], areas: ['石岐', '中山北站', '东区', '小榄', '古镇'] },
  { tier: '三线', city: '惠州', bbox: [22.75, 114.15, 23.25, 114.75], rent: [600, 2000], areas: ['江北', '惠州南站', '惠城', '仲恺', '西湖'] },
  { tier: '三线', city: '汕头', bbox: [23.15, 116.45, 23.55, 117.05], rent: [550, 1900], areas: ['金平', '汕头站', '龙湖', '万象城', '澄海'] },
  { tier: '三线', city: '海口', bbox: [19.75, 110.10, 20.15, 110.60], rent: [600, 2100], areas: ['国贸', '海口东站', '美兰', '骑楼老街', '美兰机场'] },
  { tier: '三线', city: '兰州', bbox: [35.85, 103.55, 36.30, 104.15], rent: [550, 1900], areas: ['东方红广场', '兰州西站', '城关', '七里河', '新区'] },
  { tier: '三线', city: '银川', bbox: [38.25, 106.00, 38.75, 106.45], rent: [500, 1800], areas: ['鼓楼', '银川站', '金凤', '兴庆', '河东机场'] },
  { tier: '三线', city: '西宁', bbox: [36.45, 101.55, 36.85, 102.05], rent: [500, 1800], areas: ['中心广场', '西宁站', '城西', '海湖新区', '曹家堡机场'] },
  { tier: '三线', city: '呼和浩特', bbox: [40.65, 111.45, 41.05, 112.10], rent: [500, 1800], areas: ['中山路', '呼和浩特站', '赛罕', '如意开发区', '白塔机场'] },
  { tier: '三线', city: '乌鲁木齐', bbox: [43.55, 87.25, 44.10, 88.00], rent: [550, 1900], areas: ['红山', '乌鲁木齐站', '沙依巴克', '会展中心', '地窝堡机场'] },
  { tier: '三线', city: '赣州', bbox: [25.55, 114.65, 26.05, 115.15], rent: [500, 1700], areas: ['章贡', '赣州站', '南康', '蓉江新区', '黄金机场'] },
  { tier: '三线', city: '临沂', bbox: [35.00, 118.15, 35.45, 118.75], rent: [500, 1700], areas: ['人民广场', '临沂北站', '兰山', '罗庄', '河东'] },
  { tier: '三线', city: '淄博', bbox: [36.55, 117.75, 37.05, 118.30], rent: [500, 1700], areas: ['张店', '淄博站', '万象汇', '高新区', '周村'] },
  { tier: '三线', city: '芜湖', bbox: [31.15, 118.20, 31.55, 118.60], rent: [500, 1700], areas: ['镜湖', '芜湖站', '鸠江', '弋江', '方特'] },
  { tier: '三线', city: '襄阳', bbox: [31.85, 111.85, 32.35, 112.35], rent: [500, 1700], areas: ['襄城', '襄阳东站', '樊城', '高新区', '古城'] },
  { tier: '三线', city: '宜昌', bbox: [30.45, 111.00, 30.95, 111.65], rent: [500, 1700], areas: ['夷陵广场', '宜昌东站', '伍家岗', '西陵', '三峡机场'] },
  { tier: '三线', city: '镇江', bbox: [31.80, 119.25, 32.30, 119.75], rent: [500, 1700], areas: ['大市口', '镇江站', '润州', '京口', '丹徒'] },
  { tier: '三线', city: '泰州', bbox: [32.25, 119.75, 32.75, 120.25], rent: [500, 1700], areas: ['坡子街', '泰州站', '海陵', '医药城', '姜堰'] },
  { tier: '三线', city: '湖州', bbox: [30.65, 119.85, 31.15, 120.35], rent: [500, 1700], areas: ['爱山广场', '湖州站', '吴兴', '南浔', '仁皇山'] },
  { tier: '三线', city: '廊坊', bbox: [39.35, 116.45, 39.75, 116.95], rent: [500, 1700], areas: ['万达广场', '廊坊站', '广阳', '安次', '开发区'] },
  { tier: '三线', city: '秦皇岛', bbox: [39.65, 119.35, 40.05, 119.85], rent: [500, 1800], areas: ['海港', '秦皇岛站', '北戴河', '山海关', '开发区'] },
  { tier: '三线', city: '吉林', bbox: [43.65, 126.25, 44.10, 126.85], rent: [500, 1700], areas: ['河南街', '吉林站', '昌邑', '船营', '丰满'] },
  { tier: '三线', city: '柳州', bbox: [24.15, 108.95, 24.65, 109.65], rent: [500, 1700], areas: ['五星街', '柳州站', '城中', '鱼峰', '柳东'] },
  { tier: '三线', city: '桂林', bbox: [25.05, 110.05, 25.45, 110.55], rent: [500, 1800], areas: ['正阳步行街', '桂林站', '象山', '七星', '两江机场'] },
  { tier: '三线', city: '三亚', bbox: [18.15, 109.35, 18.45, 109.75], rent: [650, 2500], areas: ['三亚湾', '亚龙湾', '三亚站', '海棠湾', '凤凰机场'] },
  { tier: '三线', city: '绵阳', bbox: [31.25, 104.45, 31.75, 105.05], rent: [500, 1700], areas: ['涪城', '绵阳站', '高新区', '经开区', '南郊机场'] },
  { tier: '三线', city: '南充', bbox: [30.65, 105.85, 31.05, 106.35], rent: [500, 1600], areas: ['五星花园', '南充站', '顺庆', '高坪', '嘉陵'] },
  { tier: '三线', city: '遵义', bbox: [27.45, 106.65, 28.05, 107.15], rent: [500, 1600], areas: ['丁字口', '遵义站', '红花岗', '新蒲', '汇川'] },
];

const fallbackAreas = ['核心商务区', '高铁站商圈', '会展中心', '医院周边', '产业园区', '景区入口', '大学城', '机场枢纽', '老城商圈', ''];
const decorationLevels = ['经济型-基础改造', '经济型-标准翻新', '中端精选-轻改', '中端精选-标准', '中端精选-品质', '中高端商务-标准', '中高端商务-品质', '度假/亲子主题'];
const targetCustomers = ['商务差旅', '会议会展', '休闲旅游', '亲子家庭', '医院陪护', '高校考培', '园区长住', '交通中转', '本地消费', '政企接待'];

function cityByName(city) {
  return majorDomesticCities.find((item) => item.city === city) || majorDomesticCities[0];
}

function parseNumber(value) {
  const matched = String(value || '').match(/\d+(\.\d+)?/);
  return matched ? Number(matched[0]) : 0;
}

function clamp(value, min, max) {
  return Math.max(min, Math.min(max, value));
}

function elementLatLon(element) {
  const lat = Number(element.lat ?? element.center?.lat);
  const lon = Number(element.lon ?? element.center?.lon);
  return Number.isFinite(lat) && Number.isFinite(lon) ? { lat, lon } : null;
}

function cityByCoordinate(element) {
  const point = elementLatLon(element);
  if (!point) return null;

  return majorDomesticCities.find((item) => {
    const [south, west, north, east] = item.bbox;
    return point.lat >= south && point.lat <= north && point.lon >= west && point.lon <= east;
  }) || null;
}

function areaFromTags(tags, cityInfo) {
  const candidates = [
    tags['addr:district'],
    tags['addr:subdistrict'],
    tags['addr:suburb'],
    tags['addr:street'],
    tags['addr:neighbourhood'],
  ];
  const value = candidates.map((item) => String(item || '').trim()).find(Boolean);
  return value || pick(cityInfo.areas || fallbackAreas);
}

function decorationFromStars(stars) {
  if (stars >= 4) return pick(['中高端商务-标准', '中高端商务-品质']);
  if (stars >= 3) return pick(['中端精选-标准', '中端精选-品质']);
  if (stars >= 2) return pick(['经济型-标准翻新', '中端精选-轻改']);
  return pick(decorationLevels);
}

function chunks(items, size) {
  const batchSize = Math.max(1, size);
  const batches = [];
  for (let index = 0; index < items.length; index += batchSize) {
    batches.push(items.slice(index, index + batchSize));
  }
  return batches;
}

function buildOverpassQuery(cityItems) {
  const typeSelector = '["tourism"~"hotel|guest_house|hostel|motel"]';
  const blocks = cityItems.map((item) => {
    const bbox = item.bbox.join(',');
    return [
      '(',
      `node${typeSelector}(${bbox});`,
      `way${typeSelector}(${bbox});`,
      `relation${typeSelector}(${bbox});`,
      ');',
      `out tags center ${overpassPerCityLimit};`,
    ].join('');
  });

  return `[out:json][timeout:${Math.ceil(overpassTimeoutMs / 1000)}];${blocks.join('')}`;
}

async function fetchNetworkHotelSeeds() {
  if (dataSourceMode === 'local') return { seeds: [], error: 'network disabled' };
  if (typeof fetch !== 'function') return { seeds: [], error: 'fetch is not available in this Node runtime' };

  const seen = new Set();
  const seeds = [];
  const errors = [];
  for (const cityBatch of chunks(majorDomesticCities, overpassBatchSize)) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), overpassTimeoutMs);
    try {
      const response = await fetch(overpassEndpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'User-Agent': 'SuxiOS-Codex-MarketEvaluation-Test/1.0',
        },
        body: new URLSearchParams({ data: buildOverpassQuery(cityBatch) }),
        signal: controller.signal,
      });
      if (!response.ok) {
        errors.push(`${cityBatch[0].city}-${cityBatch.at(-1).city}: HTTP ${response.status}`);
        continue;
      }

      const payload = await response.json();
      for (const element of payload.elements || []) {
        const key = `${element.type}:${element.id}`;
        if (seen.has(key)) continue;
        seen.add(key);

        const tags = element.tags || {};
        const cityInfo = cityByCoordinate(element);
        if (!cityInfo) continue;

        const name = String(tags['name:zh'] || tags.name || tags['name:en'] || '').trim()
          || `${cityInfo.city}网络酒店${seeds.length + 1}`;
        seeds.push({
          source: 'OpenStreetMap Overpass',
          source_id: key,
          hotel_name: name,
          city: cityInfo.city,
          tier: cityInfo.tier,
          business_area: areaFromTags(tags, cityInfo),
          stars: parseNumber(tags.stars || tags['hotel:stars'] || tags.rating),
          rooms: parseNumber(tags.rooms || tags['hotel:rooms'] || tags.capacity),
        });
      }
    } catch (error) {
      const range = `${cityBatch[0].city}-${cityBatch.at(-1).city}`;
      errors.push(`${range}: ${error?.name === 'AbortError' ? 'timed out' : String(error?.message || error)}`);
    } finally {
      clearTimeout(timer);
    }
  }

  return { seeds, error: errors.join('; ') };
}

function buildFallbackSeeds() {
  return majorDomesticCities.flatMap((cityInfo) => (cityInfo.areas || fallbackAreas).map((area, index) => ({
    source: 'local major city fallback',
    source_id: `${cityInfo.city}-${index}`,
    hotel_name: `${cityInfo.city}${area || '核心区'}样本酒店`,
    city: cityInfo.city,
    tier: cityInfo.tier,
    business_area: area,
    stars: 0,
    rooms: 0,
  })));
}

function supplementMissingCitySeeds(seeds) {
  const coveredCities = new Set(seeds.map((item) => item.city).filter(Boolean));
  if (coveredCities.size >= minimumCoveredCityCount) {
    return seeds;
  }

  const fallbackSeeds = buildFallbackSeeds()
    .filter((item) => !coveredCities.has(item.city));
  return [...seeds, ...fallbackSeeds];
}

function prioritizeCityCoverage(seeds) {
  const grouped = new Map();
  for (const seedItem of seeds) {
    if (!grouped.has(seedItem.city)) grouped.set(seedItem.city, []);
    grouped.get(seedItem.city).push(seedItem);
  }

  const ordered = [];
  const cityNames = [...grouped.keys()].sort((a, b) => a.localeCompare(b, 'zh-Hans-CN'));
  let round = 0;
  while (ordered.length < seeds.length) {
    let added = false;
    for (const city of cityNames) {
      const item = grouped.get(city)?.[round];
      if (item) {
        ordered.push(item);
        added = true;
      }
    }
    if (!added) break;
    round += 1;
  }

  return ordered;
}

function buildSample(index, seedItem, sourceType) {
  const cityInfo = cityByName(seedItem.city);
  const targetRoomCount = seedItem.rooms > 0
    ? clamp(Math.round(seedItem.rooms * (0.75 + rng() * 0.7)), 24, 180)
    : int(24, 158);
  const areaPerRoom = int(14, 64) + Number(rng().toFixed(2));
  const [rentLow, rentHigh] = cityInfo.rent;
  const rentPerRoom = int(rentLow, rentHigh) + Number((rng() * 100).toFixed(2));
  const businessArea = index % 19 === 0 ? '' : (seedItem.business_area || pick(fallbackAreas));
  const primaryCustomer = pick(targetCustomers);
  const secondaryCustomer = pick(targetCustomers.filter(item => item !== primaryCustomer));

  return {
    hotel_name: `${seedItem.hotel_name}_${String(index + 1).padStart(4, '0')}`,
    city: seedItem.city,
    business_area: businessArea,
    property_area: Math.max(1, Math.round(targetRoomCount * areaPerRoom)),
    estimated_rent: money(targetRoomCount * rentPerRoom),
    target_room_count: targetRoomCount,
    decoration_level: decorationFromStars(seedItem.stars),
    primary_customer: primaryCustomer,
    secondary_customer: secondaryCustomer,
    target_customer: `${primaryCustomer}+${secondaryCustomer}`,
    city_tier: seedItem.tier || cityInfo.tier,
    asset_type: pick(['整栋独立', '集中楼层', '裙楼改造', '园区配套', '商住混合']),
    operation_model: pick(['直营', '加盟', '托管', '联营']),
    contract_status: pick(['待谈判', '已锁定', '已签约', '需重谈']),
    lease_years: int(4, 12),
    rent_free_months: int(1, 8),
    deposit_months: int(1, 8),
    transfer_fee: money(int(0, 900000)) / 10000,
    fitout_budget: money(targetRoomCount * int(35000, 95000)) / 10000,
    expected_adr: int(160, 520),
    expected_occupancy_rate: int(55, 88),
    competitor_count: int(2, 38),
    parking_spaces: int(0, Math.max(10, Math.round(targetRoomCount * 0.7))),
    ota_market_penetration_rate: int(25, 85),
    source: seedItem.source || (sourceType === 'network' ? 'OpenStreetMap Overpass' : 'local_fallback'),
    source_id: seedItem.source_id,
  };
}

async function buildSamples() {
  const network = await fetchNetworkHotelSeeds();
  let sourceType = 'network';
  let sourceError = network.error || '';
  let seeds = network.seeds;

  if (seeds.length === 0) {
    sourceType = 'fallback';
    seeds = buildFallbackSeeds();
  } else {
    const supplemented = supplementMissingCitySeeds(seeds);
    if (supplemented.length > seeds.length) {
      sourceType = 'hybrid';
      const networkCovered = new Set(seeds.map((item) => item.city).filter(Boolean)).size;
      sourceError = [
        sourceError,
        `network covered ${networkCovered} cities; supplemented missing cities with local major-city fallback`,
      ].filter(Boolean).join('; ');
      seeds = supplemented;
    }
  }
  if (requireNetwork && !['network', 'hybrid'].includes(sourceType)) {
    console.error(`Network hotel data source is required but unavailable: ${sourceError || 'no network seeds returned'}`);
    process.exit(1);
  }

  const orderedSeeds = prioritizeCityCoverage(seeds);
  const samples = Array.from({ length: sampleSize }, (_, index) => buildSample(index, orderedSeeds[index % orderedSeeds.length], sourceType));
  const cityCounts = new Map();
  const tierCounts = new Map();
  for (const sample of samples) {
    cityCounts.set(sample.city, (cityCounts.get(sample.city) || 0) + 1);
    tierCounts.set(sample.city_tier, (tierCounts.get(sample.city_tier) || 0) + 1);
  }

  return {
    samples,
    source: {
      type: sourceType,
      provider: sourceType === 'network'
        ? 'OpenStreetMap Overpass'
        : (sourceType === 'hybrid' ? 'OpenStreetMap Overpass + local fallback' : 'local fallback'),
      seed_count: seeds.length,
      error: sourceError,
      covered_city_count: cityCounts.size,
      covered_cities: [...cityCounts.keys()].sort(),
      city_counts: Object.fromEntries([...cityCounts.entries()].sort((a, b) => a[0].localeCompare(b[0], 'zh-Hans-CN'))),
      tier_counts: Object.fromEntries([...tierCounts.entries()].sort((a, b) => a[0].localeCompare(b[0], 'zh-Hans-CN'))),
    },
  };
}

const { samples, source } = await buildSamples();

const phpCode = `
require getcwd() . '/vendor/autoload.php';
$samples = json_decode(stream_get_contents(STDIN), true);
$service = new app\\service\\ExpansionService();
$payload = ['results' => [], 'failures' => []];
foreach ($samples as $index => $input) {
    try {
        $payload['results'][] = [
            'index' => $index,
            'result' => $service->evaluateMarket($input),
        ];
    } catch (Throwable $e) {
        $payload['failures'][] = [
            'index' => $index,
            'message' => $e->getMessage(),
            'input' => $input,
        ];
    }
}
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
`;

const phpResult = spawnSync(php, ['-r', phpCode], {
  cwd: root,
  input: JSON.stringify(samples),
  encoding: 'utf8',
  timeout: 120000,
  maxBuffer: 64 * 1024 * 1024,
  shell: false,
});

if (phpResult.status !== 0) {
  console.error('Market evaluation random sample failed before validation.');
  console.error((phpResult.error?.message || phpResult.stderr || phpResult.stdout || '').trim());
  process.exit(1);
}

let evaluated;
try {
  evaluated = JSON.parse(phpResult.stdout);
} catch {
  console.error('Market evaluation random sample returned invalid JSON.');
  console.error((phpResult.stdout || '').slice(0, 1000));
  process.exit(1);
}

const failures = [...(evaluated.failures || [])];
const requiredKeys = [
  'market_heat_score',
  'supply_competition_strength',
  'price_band_suggestion',
  'investment_risk_level',
  'recommended_property_type',
  'ai_operation_suggestions',
  'not_recommended_risks',
  'metrics',
  'decision',
  'data_status',
  'rule_reasons',
  'investment_conditions',
];

const scoreBuckets = { low: 0, medium: 0, high: 0 };
const riskCounts = new Map();
const decisionCounts = new Map();
let minScore = 100;
let maxScore = 0;
let missingAreaCount = 0;

function addFailure(index, message) {
  failures.push({ index, message, input: samples[index] });
}

for (const item of evaluated.results || []) {
  const index = item.index;
  const result = item.result || {};
  const input = samples[index] || {};

  for (const key of requiredKeys) {
    if (!(key in result)) addFailure(index, `missing result key: ${key}`);
  }

  const score = result.market_heat_score;
  if (!Number.isInteger(score) || score < 0 || score > 100) {
    addFailure(index, `invalid market_heat_score: ${score}`);
  } else {
    minScore = Math.min(minScore, score);
    maxScore = Math.max(maxScore, score);
    if (score < 60) scoreBuckets.low += 1;
    else if (score < 75) scoreBuckets.medium += 1;
    else scoreBuckets.high += 1;
  }

  if (!Array.isArray(result.ai_operation_suggestions) || result.ai_operation_suggestions.length === 0) {
    addFailure(index, 'ai_operation_suggestions must be a non-empty array');
  }
  if (!Array.isArray(result.not_recommended_risks) || result.not_recommended_risks.length === 0) {
    addFailure(index, 'not_recommended_risks must be a non-empty array');
  }
  if (!Array.isArray(result.rule_reasons)) {
    addFailure(index, 'rule_reasons must be an array');
  }

  for (const metricKey of ['area_per_room', 'rent_per_room', 'rent_per_square']) {
    const value = result.metrics?.[metricKey];
    if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) {
      addFailure(index, `invalid metric ${metricKey}: ${value}`);
    }
  }

  if (!result.decision) addFailure(index, 'decision must not be empty');
  if (!result.investment_risk_level) addFailure(index, 'investment_risk_level must not be empty');
  riskCounts.set(result.investment_risk_level, (riskCounts.get(result.investment_risk_level) || 0) + 1);
  decisionCounts.set(result.decision, (decisionCounts.get(result.decision) || 0) + 1);

  const missingFields = Array.isArray(result.data_status?.missing_fields) ? result.data_status.missing_fields : [];
  if (input.business_area === '') {
    missingAreaCount += 1;
    if (!missingFields.includes('商圈/区域')) {
      addFailure(index, 'blank business_area must be reported in data_status.missing_fields');
    }
  }
}

if ((evaluated.results || []).length !== sampleSize) {
  addFailure(0, `expected ${sampleSize} evaluated results, got ${(evaluated.results || []).length}`);
}
if (riskCounts.size < 2) {
  addFailure(0, 'random sample should cover at least two investment risk levels');
}
if (decisionCounts.size < 2) {
  addFailure(0, 'random sample should cover at least two decision outcomes');
}
if (missingAreaCount === 0) {
  addFailure(0, 'random sample should include blank business_area cases');
}
if (!source.type) {
  addFailure(0, 'data source type must not be empty');
}
if (source.covered_city_count < minimumCoveredCityCount) {
  addFailure(0, `expected coverage for at least ${minimumCoveredCityCount} major domestic tier-1/2/3 cities, got ${source.covered_city_count}`);
}
for (const tier of ['一线', '二线', '三线']) {
  if (!source.tier_counts?.[tier]) {
    addFailure(0, `expected coverage for ${tier} cities`);
  }
}

if (failures.length > 0) {
  console.error(`Market evaluation random sample failed: ${failures.length} issue(s).`);
  for (const failure of failures.slice(0, 10)) {
    console.error(`- #${failure.index + 1}: ${failure.message}`);
  }
  if (failures.length > 10) {
    console.error(`- ... ${failures.length - 10} more`);
  }
  process.exit(1);
}

const riskText = [...riskCounts.entries()].map(([name, count]) => `${name}:${count}`).join(', ');
const decisionText = [...decisionCounts.entries()].map(([name, count]) => `${name}:${count}`).join(', ');

console.log('Market evaluation random sample passed.');
console.log(`cases=${sampleSize} seed=${seed} score_range=${minScore}-${maxScore}`);
console.log(`source=${source.type} provider=${source.provider} seed_count=${source.seed_count} covered_city_count=${source.covered_city_count}`);
console.log(`covered_cities=${source.covered_cities.join(',')}`);
console.log(`tier_counts=${Object.entries(source.tier_counts).map(([name, count]) => `${name}:${count}`).join(',')}`);
console.log(`score_buckets=low:${scoreBuckets.low}, medium:${scoreBuckets.medium}, high:${scoreBuckets.high}`);
console.log(`risk_levels=${riskText}`);
console.log(`decisions=${decisionText}`);
console.log(`blank_business_area_cases=${missingAreaCount}`);
if (source.type !== 'network' && source.error) {
  console.log(`network_fallback_reason=${source.error}`);
}
