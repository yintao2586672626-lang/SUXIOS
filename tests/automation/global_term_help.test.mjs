import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const systemStaticSource = read('public/system-static.js');
const appMain = read('public/app-main.js');
const styleCss = read('public/style.css');
const sandbox = { window: {}, console, setTimeout, clearTimeout };

vm.runInNewContext(systemStaticSource, sandbox, { filename: 'public/system-static.js' });
const {
  suxiTermHelpGlossary,
  resolveSuxiTermHelp,
  createSuxiTermHelpComponent,
} = sandbox.window.SUXI_SYSTEM_STATIC;

assert.ok(Object.keys(suxiTermHelpGlossary).length >= 20, 'global glossary should cover the core hotel and traffic terms');

const adr = resolveSuxiTermHelp('加权 ADR', { scope: '当前模拟情景' });
assert.equal(adr.key, 'adr');
assert.match(adr.definition, /平均房价/);
assert.match(adr.formula, /房费收入 ÷ 出租间夜/);
assert.match(adr.text, /范围：当前模拟情景/);

const occ = resolveSuxiTermHelp('入住率');
assert.equal(occ.key, 'occ');
assert.match(occ.formula, /出租间夜 ÷ 可售房夜/);

const revpar = resolveSuxiTermHelp('RevPAR');
assert.equal(revpar.key, 'revpar');
assert.match(revpar.formula, /ADR × OCC/);

const conversion = resolveSuxiTermHelp('conversion_rate');
assert.equal(conversion.key, 'conversion_rate');
assert.match(conversion.formula, /具体分子、分母以当前页面标注为准/);

const ota = resolveSuxiTermHelp('OTA');
assert.match(ota.definition, /只代表对应渠道/);
assert.match(ota.formula, /不得自动扩大为全酒店/);

assert.equal(resolveSuxiTermHelp('保存'), null, 'ordinary action labels should stay plain');
assert.equal(resolveSuxiTermHelp('状态'), null, 'ordinary status labels should stay plain');

const h = (tag, props, children) => ({ tag, props, children });
const component = createSuxiTermHelpComponent(h);
const props = { term: 'roi', label: 'ROI', scope: '同口径执行复盘' };
const render = component.setup(props, { attrs: { class: 'text-xs', 'data-testid': 'roi-help' } });
const vnode = render();
assert.equal(vnode.tag, 'span');
assert.equal(vnode.props.role, 'button');
assert.equal(vnode.props.tabindex, '0');
assert.match(vnode.props['data-help'], /净收益 ÷ 投入成本/);
assert.match(vnode.props['aria-label'], /同口径执行复盘/);
assert.equal(vnode.props['data-testid'], 'roi-help');

assert.match(appMain, /const createSuxiTermHelpComponent = requireAppSystemStatic\('createSuxiTermHelpComponent'\);/);
assert.match(appMain, /app\.component\('TermHelp', createSuxiTermHelpComponent\(h\)\);/);
assert.match(styleCss, /\.suxi-term-help:hover::after/);
assert.match(styleCss, /\.suxi-term-help:focus::after/);
assert.match(styleCss, /@media \(hover: hover\) and \(pointer: fine\)/);
assert.match(styleCss, /width: min\(21rem, calc\(100vw - 2rem\)\)/);
assert.match(styleCss, /@media \(max-width: 639px\)[\s\S]*?position: fixed;[\s\S]*?right: 1rem;[\s\S]*?left: 1rem;/);

const coveredFragments = [
  '02-page-ai-simulation.html',
  '03-page-ai-feasibility.html',
  '15aa-page-operating-targets.html',
  '15a-page-ops-source.html',
  '15aab-page-pms-operating-data.html',
  '16-page-ai-daily-report.html',
  '17-page-ops-track.html',
  '24-page-ctrip-ebooking.html',
  '26-page-meituan-ebooking.html',
  '27-page-agent-center.html',
  '28-page-investment-decision.html',
  '34-page-data-config.html',
  '35-page-online-data.html',
];

for (const file of coveredFragments) {
  const source = read(`resources/frontend/templates/fragments/${file}`);
  assert.match(source, /<term-help\b/, `${file} should use the global progressive-disclosure component`);
}

console.log(`global term help checks passed across ${coveredFragments.length} interface fragments`);
