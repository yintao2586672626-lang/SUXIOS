import assert from 'node:assert/strict';
import test from 'node:test';
import { compile } from '@vue/compiler-dom';
import * as Vue from 'vue';
import {
  buildFrontendTemplateRender,
  compileFrontendTemplate,
} from '../../scripts/lib/frontend_template_build.mjs';

const sharedClass = 'flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white px-6 py-4 text-sm font-medium text-slate-700';
const template = `<main>
  <section v-if="outer" class="${sharedClass}">
    <p v-if="inner" class="${sharedClass}">{{ label }}</p>
    <p v-else-if="alternate" class="${sharedClass}">{{ alternateLabel }}</p>
    <div v-for="item in items" :key="item.id" class="${sharedClass}" :class="{ selected: item.selected }">
      <span v-if="item.visible" class="${sharedClass}">{{ item.label }}</span>
    </div>
  </section>
  <aside v-if="aside" class="${sharedClass}">{{ label }}</aside>
  <footer class="${sharedClass}" :class="dynamicClass">{{ label }}</footer>
</main>`;

function summarize(vnode) {
  if (!vnode || typeof vnode !== 'object') return vnode;
  return {
    type: vnode.type,
    key: vnode.key,
    props: vnode.props,
    shapeFlag: vnode.shapeFlag,
    patchFlag: vnode.patchFlag,
    dynamicProps: vnode.dynamicProps,
    children: Array.isArray(vnode.children) ? vnode.children.map(summarize) : vnode.children,
    dynamicChildren: vnode.dynamicChildren?.map(summarize) ?? null,
  };
}

function commentNodes(vnode) {
  if (!vnode || typeof vnode !== 'object') return [];
  return [
    ...(vnode.type === Vue.Comment ? [vnode] : []),
    ...(Array.isArray(vnode.children) ? vnode.children.flatMap(commentNodes) : []),
  ];
}

function baselineRender() {
  const source = compile(template, {
    mode: 'function', prefixIdentifiers: true, hoistStatic: false, comments: false,
  }).code.replaceAll('_createCommentVNode("v-if", true)', '_createCommentVNode("", true)');
  return new Function('Vue', source)(Vue);
}

const states = [
  { outer: false, inner: false, alternate: false, aside: false },
  { outer: true, inner: false, alternate: false, aside: true },
  { outer: true, inner: false, alternate: true, aside: false },
  { outer: true, inner: true, alternate: false, aside: true },
  { outer: false, inner: true, alternate: true, aside: false },
].map((state, index) => ({
  ...state,
  label: `render ${index}`,
  alternateLabel: `alternate ${index}`,
  dynamicClass: index % 2 ? ['active', { highlighted: true }] : { inactive: true },
  items: [
    { id: 1, label: 'first', selected: index % 2 === 0, visible: false },
    { id: 2, label: 'second', selected: index % 2 !== 0, visible: index % 2 === 0 },
  ],
}));

test('static class pooling preserves Vue vnode shapes, flags and nested branch updates', async () => {
  const baseline = baselineRender();
  const compiled = new Function('Vue', compileFrontendTemplate(template))(Vue);
  const runtimeWindow = {};
  new Function('Vue', 'window', await buildFrontendTemplateRender(template))(Vue, runtimeWindow);
  for (const render of [compiled, runtimeWindow.SUXI_APP_RENDER]) {
    for (const state of states) {
      assert.deepEqual(summarize(render(state, [])), summarize(baseline(state, [])));
    }
  }
});

test('compiled conditional anchors remain independent fresh Vue comment vnodes', async () => {
  const runtimeWindow = {};
  new Function('Vue', 'window', await buildFrontendTemplateRender(template))(Vue, runtimeWindow);
  for (const render of [new Function('Vue', compileFrontendTemplate(template))(Vue), runtimeWindow.SUXI_APP_RENDER]) {
    const seen = new Set();
    for (const state of [...states, ...states]) {
      for (const comment of commentNodes(render(state, []))) {
        assert.equal(comment.children, '');
        assert.equal(comment.shapeFlag, 8);
        assert.equal(comment.patchFlag, 0);
        assert.equal(seen.has(comment), false, 'each render position and invocation needs its own comment vnode');
        seen.add(comment);
      }
    }
    assert.ok(seen.size >= 10, 'nested and sibling false branches must exercise multiple anchors');
  }
});
