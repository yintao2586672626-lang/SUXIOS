import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const html = readFileSync(join(root, 'public/index.html'), 'utf8');

const stripHtmlComments = (content) => content.replace(/<!--[\s\S]*?-->/g, '');
const pageMatch = html.match(/<div v-if="currentPage === 'ai-model-config'">[\s\S]*?<div v-if="currentPage === 'data-config'">/);
const modalMatch = html.match(/<div v-if="showAiModelConfigModal"[\s\S]*?<!-- 系统配置模态框 -->/);
const scriptMatch = html.match(/const loadAiModelConfigs = async \(\) => \{[\s\S]*?const handleLogin = async \(\) => \{/);
const failures = [];
const localeSwitchCount = (html.match(/data-locale-switch/g) || []).length;
const appHeaderMatch = html.match(/<header class="header px-6 py-4 sticky top-0 z-10">[\s\S]*?<\/header>/);
const initialLocaleMatch = html.match(/const getInitialLocale = \(\) => \{[\s\S]*?\n    \};/);

if (localeSwitchCount < 2) {
  failures.push('global locale switch should appear on login page and app header');
}

if (!appHeaderMatch) {
  failures.push('app header block not found');
} else {
  const headerBlock = appHeaderMatch[0];
  if (!headerBlock.includes('flex flex-col sm:flex-row')) {
    failures.push('app header should wrap language switch and clock on narrow screens');
  }
  if (!headerBlock.includes('flex flex-wrap items-center justify-end')) {
    failures.push('app header actions should use flex-wrap');
  }
}

if (!initialLocaleMatch) {
  failures.push('getInitialLocale block not found');
} else {
  const localeBlock = initialLocaleMatch[0];
  const langIndex = localeBlock.indexOf("params.get('lang')");
  const localStorageIndex = localeBlock.indexOf("localStorage.getItem('suxios_locale')");
  if (langIndex === -1 || localStorageIndex === -1 || langIndex > localStorageIndex) {
    failures.push('URL language parameter should take priority over cached locale');
  }
}

if (!pageMatch) {
  failures.push('AI model config page block not found');
} else {
  const pageBlock = stripHtmlComments(pageMatch[0]);
  const forbiddenLiterals = [
    'AI模型配置',
    '管理 DeepSeek、OpenAI 等 OpenAI 兼容模型',
    '刷新',
    '高级配置',
    '快速配置AI厂家',
    '选择厂家并填写 API Key，系统自动生成可用模型配置。',
    '模型厂家',
    '请输入 API Key',
    '配置中...',
    '保存并自动配置',
    '手动维护 model_key、provider、base_url、model_name 和使用场景。',
    '新增手动配置',
    '显示名称',
    '供应商',
    '模型',
    '场景',
    '状态',
    '操作',
    '加载中...',
    '默认',
    '未配置',
    '启用',
    '禁用',
    '编辑',
    '测试连接',
    '暂无模型配置',
  ];

  for (const literal of forbiddenLiterals) {
    if (pageBlock.includes(literal)) {
      failures.push(`AI model config page still hard-codes: ${literal}`);
    }
  }
}

if (!modalMatch) {
  failures.push('AI model config modal block not found');
} else {
  const modalBlock = stripHtmlComments(modalMatch[0]);
  const forbiddenLiterals = [
    '高级配置：编辑AI模型',
    '高级配置：新增AI模型',
    '显示名称',
    '留空则不覆盖已有 Key',
    '请输入 API Key',
    '列表只显示脱敏 Key；编辑时留空不会覆盖旧 Key。',
    '当前：',
    '使用场景',
    '日常经营诊断',
    '设为默认',
    '用于默认模型选择',
    '启用模型',
    '禁用后不可用于调用',
    '取消',
    '保存',
  ];

  for (const literal of forbiddenLiterals) {
    if (modalBlock.includes(literal)) {
      failures.push(`AI model config modal still hard-codes: ${literal}`);
    }
  }
}

if (!scriptMatch) {
  failures.push('AI model config script block not found');
} else {
  const scriptBlock = scriptMatch[0];
  const forbiddenLiterals = [
    '加载AI模型配置失败',
    '模型配置已更新',
    '模型配置已创建',
    '保存失败',
    '模型已启用',
    '模型已禁用',
    '操作失败',
    '模型连接测试成功',
    '模型连接测试失败',
  ];

  for (const literal of forbiddenLiterals) {
    if (scriptBlock.includes(literal)) {
      failures.push(`AI model config script still hard-codes: ${literal}`);
    }
  }
}

const requiredSnippets = [
  'aiModelConfigI18n',
  "'zh-CN'",
  "'en-US'",
  "aiModelConfig.pageTitle",
  "'aiModelConfig.pageTitle': 'AI模型配置'",
  "'aiModelConfig.pageTitle': 'AI Model Configuration'",
  "aiModelQuickSetup.quickTitle",
  "'aiModelQuickSetup.quickTitle': '快速配置AI厂家'",
  "'aiModelQuickSetup.quickTitle': 'Quick AI Provider Setup'",
  "'aiModelConfig.modalEditTitle': '高级配置：编辑AI模型'",
  "'aiModelConfig.modalEditTitle': 'Advanced Configuration: Edit AI Model'",
  "'aiModelConfig.testSuccess': '模型连接测试成功'",
  "'aiModelConfig.testSuccess': 'Model connection test succeeded'",
  "'global.languageLabel': '语言'",
  "'global.languageLabel': 'Language'",
  'aiModelConfigText',
  'languageOptions',
  'switchLocale',
  "params.get('lang') ||",
  "params.get('locale') ||",
  "params.get('think_lang') ||",
  "localStorage.getItem('suxios_locale') ||",
  "localStorage.setItem('suxios_locale', normalized)",
  'document.documentElement.lang = normalized',
  'updateCurrentTime',
  'new Date().toLocaleString(currentLocale.value)',
  '@change="switchLocale($event.target.value)"',
];

for (const snippet of requiredSnippets) {
  if (!html.includes(snippet)) {
    failures.push(`missing i18n contract: ${snippet}`);
  }
}

if (failures.length > 0) {
  console.error(`AI model config i18n verification failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('AI model config i18n verification passed.');
