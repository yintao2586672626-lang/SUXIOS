/* ============================================
   全局类型扩展 - Vue / DOM / 浏览器 API
   ============================================ */

/** 声明全局常量（CDN 引入的库） */
declare const Vue: {
  createApp: typeof import('vue').createApp;
  ref: typeof import('vue').ref;
  reactive: typeof import('vue').reactive;
  computed: typeof import('vue').computed;
  onMounted: typeof import('vue').onMounted;
  watch: typeof import('vue').watch;
  nextTick: typeof import('vue').nextTick;
  toRefs: typeof import('vue').toRefs;
};

/** Vue 组件选项接口 */
interface ComponentOptions {
  props?: string[];
  emits?: string[];
  template?: string;
  setup?: () => Record<string, unknown>;
  [key: string]: unknown;
}

/** localStorage 扩展 */
interface Storage {
  getItem(key: 'token'): string | null;
  getItem(key: 'remembered_username'): string | null;
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
  removeItem(key: string): void;
}

/** console 方法扩展 */
interface Console {
  warn(...args: unknown[]): void;
  error(...args: unknown[]): void;
  log(...args: unknown[]): void;
}

/** copy() 函数（项目内全局函数） */
declare function copy(text: string): void;

/** openTargetSite() 函数（项目内全局函数） */
declare function openTargetSite(url: string): void;

/** $notify / toast 等全局方法（如已注册） */
// interface Window { ... }
