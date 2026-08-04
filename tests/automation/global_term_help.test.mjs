import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const systemStaticSource = read('public/system-static.js');
const appMain = read('public/app-main.js');
const styleCss = read('public/style.css');
class DocumentStub extends EventTarget {
  constructor() {
    super();
    this.activeElement = null;
    this.listeners = new Map();
  }

  addEventListener(type, listener, options) {
    super.addEventListener(type, listener, options);
    if (!this.listeners.has(type)) this.listeners.set(type, new Set());
    this.listeners.get(type).add(listener);
  }

  removeEventListener(type, listener, options) {
    super.removeEventListener(type, listener, options);
    this.listeners.get(type)?.delete(listener);
    if (this.listeners.get(type)?.size === 0) this.listeners.delete(type);
  }

  listenerCount(type) {
    return this.listeners.get(type)?.size || 0;
  }
}
const documentStub = new DocumentStub();
const sandbox = { window: {}, document: documentStub, console, setTimeout, clearTimeout };

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
const createComponentInstance = (props, attrs = {}) => {
  const instance = {
    ...component.data(),
    ...props,
    $attrs: attrs,
    $el: { ownerDocument: documentStub },
  };
  for (const [name, method] of Object.entries(component.methods)) {
    instance[name] = method.bind(instance);
  }
  return instance;
};
const instance = createComponentInstance(
  { term: 'roi', label: 'ROI', scope: '同口径执行复盘' },
  { class: 'text-xs', 'data-testid': 'roi-help' },
);
const vnode = component.render.call(instance);
assert.equal(vnode.tag, 'button');
assert.equal(vnode.props.type, 'button');
assert.equal(vnode.props.role, undefined, 'native button semantics should not be recreated with ARIA');
assert.match(vnode.props['data-help'], /净收益 ÷ 投入成本/);
assert.equal(vnode.props['aria-label'], 'ROI');
assert.equal(vnode.props['data-testid'], 'roi-help');
assert.match(vnode.props['aria-describedby'], /^suxi-term-help-tooltip-\d+$/);

const tooltip = vnode.children[2];
assert.equal(tooltip.tag, 'span');
assert.equal(tooltip.props.id, vnode.props['aria-describedby']);
assert.equal(tooltip.props.role, 'tooltip');
assert.match(tooltip.children, /同口径执行复盘/);
assert.equal(component.render.call(instance).props['aria-describedby'], tooltip.props.id, 'tooltip ID should stay stable across renders');

const secondInstance = createComponentInstance({ term: 'adr', label: 'ADR' });
assert.notEqual(
  component.render.call(secondInstance).props['aria-describedby'],
  tooltip.props.id,
  'each tooltip should have a unique stable ID',
);

for (let repeat = 0; repeat < 3; repeat += 1) {
  instance.termHelpOpen = false;
  instance.termHelpDismissed = false;
  let clickDefaultPrevented = false;
  let clickPropagationStopped = false;
  const clickEvent = {
    preventDefault: () => { clickDefaultPrevented = true; },
    stopPropagation: () => { clickPropagationStopped = true; },
  };
  vnode.props.onClick(clickEvent);
  assert.equal(clickDefaultPrevented, true, 'help activation should not trigger a containing label or form action');
  assert.equal(clickPropagationStopped, true, 'help activation should not trigger a containing sortable header');
  assert.equal(instance.termHelpOpen, true, `tap/click repeat ${repeat + 1} should open help`);
  vnode.props.onClick(clickEvent);
  assert.equal(instance.termHelpOpen, false, `second activation repeat ${repeat + 1} should close help`);
  assert.equal(instance.termHelpDismissed, true, 'closed help should stay dismissed while the trigger retains focus');
  vnode.props.onClick(clickEvent);
  assert.equal(instance.termHelpOpen, true, `third activation repeat ${repeat + 1} should reopen help`);
}

let escapePrevented = false;
vnode.props.onKeydown({ key: 'Escape', preventDefault: () => { escapePrevented = true; } });
assert.equal(escapePrevented, true);
assert.equal(instance.termHelpOpen, false);
assert.equal(instance.termHelpDismissed, true, 'Escape should dismiss focused help without moving focus');

instance.handleTermHelpPointerEnter();
instance.termHelpDismissed = false;
instance.handleTermHelpDocumentKeydown({ key: 'Escape', preventDefault() {} });
assert.equal(instance.termHelpDismissed, true, 'Escape should also dismiss hover-only help');
instance.handleTermHelpPointerLeave();
assert.equal(instance.termHelpDismissed, false, 'hover dismissal should reset after the pointer leaves an unfocused trigger');

const dispatchDocumentEscape = () => {
  const event = new Event('keydown', { cancelable: true });
  Object.defineProperty(event, 'key', { value: 'Escape' });
  documentStub.dispatchEvent(event);
};

component.mounted.call(instance);
component.mounted.call(instance);
component.mounted.call(secondInstance);
assert.equal(documentStub.listenerCount('keydown'), 1, 'all mounted help instances should share one document Escape listener');
instance.termHelpPointerInside = true;
secondInstance.termHelpOpen = true;
dispatchDocumentEscape();
assert.equal(instance.termHelpDismissed, true);
assert.equal(secondInstance.termHelpDismissed, true, 'the shared dispatcher should dismiss every active help instance');

component.beforeUnmount.call(instance);
assert.equal(documentStub.listenerCount('keydown'), 1, 'unmounting one instance must preserve the shared listener for another');
secondInstance.termHelpOpen = true;
secondInstance.termHelpDismissed = false;
dispatchDocumentEscape();
assert.equal(secondInstance.termHelpDismissed, true, 'the remaining instance should still receive Escape after a peer unmounts');

component.beforeUnmount.call(secondInstance);
assert.equal(documentStub.listenerCount('keydown'), 0, 'the final unmount should fully remove the shared Escape listener');

const unknownInstance = createComponentInstance({ term: '保存', label: '保存' }, { class: 'plain-label' });
const unknownVnode = component.render.call(unknownInstance);
assert.equal(unknownVnode.tag, 'span');
assert.equal(unknownVnode.props.class, 'plain-label');

assert.match(appMain, /const createSuxiTermHelpComponent = requireAppSystemStatic\('createSuxiTermHelpComponent'\);/);
assert.match(appMain, /app\.component\('TermHelp', createSuxiTermHelpComponent\(h\)\);/);
assert.match(styleCss, /\.suxi-term-help:focus-visible[\s\S]*?outline: 2px solid/);
assert.match(styleCss, /min-width: 24px;[\s\S]*?min-height: 24px;/);
assert.match(styleCss, /\.suxi-term-help:hover:not\(\.is-dismissed\) > \.suxi-term-help-tooltip/);
assert.match(styleCss, /\.suxi-term-help:focus:not\(\.is-dismissed\) > \.suxi-term-help-tooltip/);
assert.match(styleCss, /\.suxi-term-help\.is-open:not\(\.is-dismissed\) > \.suxi-term-help-tooltip/);
assert.match(styleCss, /@media \(hover: hover\) and \(pointer: fine\)/);
assert.match(styleCss, /width: min\(21rem, calc\(100vw - 2rem\)\)/);
assert.match(styleCss, /overflow-wrap: anywhere;/);
assert.match(styleCss, /@media \(max-width: 639px\)[\s\S]*?\.suxi-term-help-tooltip[\s\S]*?position: fixed;[\s\S]*?right: 1rem;[\s\S]*?left: 1rem;[\s\S]*?overflow-y: auto;/);

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
const expectedTermHelpCallSites = new Map(coveredFragments.map(file => [file, 1]));
// Ctrip exposes the same two metric-help entry points in both the live ranking
// view and the fetched-record download view, so all four sites are intentional.
expectedTermHelpCallSites.set('24-page-ctrip-ebooking.html', 4);

for (const file of coveredFragments) {
  const source = read(`resources/frontend/templates/fragments/${file}`);
  assert.match(source, /<term-help\b/, `${file} should use the global progressive-disclosure component`);
}

const interactiveAncestorTags = new Set(['a', 'button', 'label', 'select', 'summary', 'textarea']);
const voidTags = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
let declaredTermHelpCount = 0;
for (const file of coveredFragments) {
  const source = read(`resources/frontend/templates/fragments/${file}`);
  const stack = [];
  let fragmentTermHelpCount = 0;
  const tokens = source.matchAll(/<\/?([a-z][\w-]*)(?:\s[^<>]*?)?\/?>/gi);
  for (const token of tokens) {
    const markup = token[0];
    const tag = token[1].toLowerCase();
    if (markup.startsWith('</')) {
      const openIndex = stack.map(entry => entry.tag).lastIndexOf(tag);
      if (openIndex >= 0) stack.length = openIndex;
      continue;
    }
    if (tag === 'term-help') {
      declaredTermHelpCount += 1;
      fragmentTermHelpCount += 1;
      const interactiveAncestors = stack.filter(({ tag: ancestorTag, markup: ancestorMarkup }) => (
        interactiveAncestorTags.has(ancestorTag)
        || /\brole\s*=\s*["'](?:button|link|checkbox|radio|switch|tab|menuitem)["']/i.test(ancestorMarkup)
      ));
      assert.deepEqual(
        interactiveAncestors,
        [],
        `${file} must not render the TermHelp button inside another interactive element`,
      );
    }
    if (!markup.endsWith('/>') && !voidTags.has(tag)) stack.push({ tag, markup });
  }
  assert.equal(
    fragmentTermHelpCount,
    expectedTermHelpCallSites.get(file),
    `${file} TermHelp call-site inventory should stay explicit`,
  );
}
assert.equal(
  declaredTermHelpCount,
  [...expectedTermHelpCallSites.values()].reduce((total, count) => total + count, 0),
  'the declared TermHelp call-site inventory should stay exact',
);

const feasibilityFragment = read('resources/frontend/templates/fragments/03-page-ai-feasibility.html');
assert.match(feasibilityFragment, /<label for="ai-feasibility-adr" class="sr-only">预期ADR\(元\)<\/label>/);
assert.match(feasibilityFragment, /<input id="ai-feasibility-adr"[^>]*v-model\.number="aiProject\.adr"/);

console.log(`global term help checks passed across ${coveredFragments.length} interface fragments`);
