#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { writeFileSync } from 'node:fs';
import { gunzipSync } from 'node:zlib';
import vm from 'node:vm';

const SOURCE_URL = 'https://sop.fjhoteltools.cn/data.js';
const VERSION = '2026-07-17';
const SEED_OWNER = 'suxios.ota_sop.materialized_reference';

const roleMap = {
  daily: ['OTA运营专员', '店长', '前台'],
  onboarding: ['OTA运营负责人', '店长', '前台', '财务'],
  diagnosis: ['OTA运营负责人', '收益经理', '店长'],
  metrics: ['OTA运营专员', '收益经理'],
  revenue: ['收益经理', '店长', 'OTA运营负责人'],
  'page-design': ['OTA运营专员', '营销负责人'],
  pricing: ['收益经理', 'OTA运营负责人'],
  promotion: ['OTA运营专员', '收益经理', '店长'],
  reviews: ['前台', '客房主管', 'OTA运营专员'],
  negative: ['店长', '前台', 'OTA运营专员'],
  'review-cycle': ['店长', 'OTA运营负责人', '收益经理'],
  performance: ['店长', '区域经理', 'OTA运营负责人'],
};

const platformMap = {
  platforms: ['ctrip', 'meituan', 'fliggy', 'other_ota', 'platform_rule_snapshot'],
};

const flattenText = (value) => {
  if (Array.isArray(value)) return value.flatMap(flattenText);
  if (value && typeof value === 'object') return Object.values(value).flatMap(flattenText);
  const text = String(value ?? '').trim();
  return text ? [text] : [];
};

const taskSteps = (fields) => {
  const preferred = fields.filter((field) => /步骤|顺序|动作|操作|流程|准备|处理|检查/.test(String(field.label ?? '')));
  const base = preferred.length ? preferred : fields;
  return base.flatMap((field) => flattenText(field.content)).slice(0, 12);
};

const acceptanceCriteria = (fields) => {
  const selected = fields.filter((field) => /合格|复查|输出|验收|停止|判断/.test(String(field.label ?? '')));
  const values = selected.flatMap((field) => flattenText(field.content));
  return values.length ? values.slice(0, 8) : ['执行结果有负责人、完成时间、证据和复查指标。'];
};

const hexJson = (value) => Buffer.from(JSON.stringify(value), 'utf8').toString('hex').toUpperCase();
const hexText = (value) => Buffer.from(String(value), 'utf8').toString('hex').toUpperCase();
const jsonSql = (value) => `JSON_EXTRACT(CONVERT(0x${hexJson(value)} USING utf8mb4), '$')`;
const textSql = (value) => `CONVERT(0x${hexText(value)} USING utf8mb4)`;

const sourceArgument = process.argv.find((value) => value.startsWith('--source-gzip-base64='));
let source;
if (sourceArgument) {
  source = gunzipSync(Buffer.from(sourceArgument.slice('--source-gzip-base64='.length), 'base64')).toString('utf8');
} else {
  const response = await fetch(SOURCE_URL, { headers: { 'user-agent': 'SUXIOS authorized public-reference reviewer' } });
  if (!response.ok) throw new Error(`SOP source returned HTTP ${response.status}`);
  source = await response.text();
}
const sourceHash = createHash('sha256').update(source).digest('hex');
const context = {};
vm.createContext(context);
vm.runInContext(`${source}\n;globalThis.__SOP_DATA__ = SOP_DATA;`, context, { timeout: 5000 });
const data = context.__SOP_DATA__;
if (!data || typeof data !== 'object' || !data.modules) throw new Error('SOP_DATA was not found');

const modules = [];
const sections = [];
const cards = [];
let fieldCount = 0;
for (const [moduleId, module] of Object.entries(data.modules)) {
  const moduleSectionKeys = [];
  for (const [sectionIndex, section] of (module.sections ?? []).entries()) {
    const sectionKey = `${moduleId}.section.${sectionIndex + 1}`;
    moduleSectionKeys.push(sectionKey);
    const sectionCards = [];
    for (const [cardIndex, card] of (section.cards ?? []).entries()) {
      const cardKey = `${sectionKey}.card.${cardIndex + 1}`;
      const fields = Array.isArray(card.fields) ? card.fields : [];
      fieldCount += fields.length;
      sectionCards.push(cardKey);
      cards.push({
        seed_owner: SEED_OWNER,
        seed_version: VERSION,
        content_key: cardKey,
        content_type: 'sop_card',
        source_snapshot_hash: sourceHash,
        source_refs: [SOURCE_URL, 'https://sop.fjhoteltools.cn/'],
        evidence_level: 'external_public_reference_reviewed',
        scope: 'ota_channel_reference_template',
        module_id: moduleId,
        module_name: module.title,
        module_summary: module.summary,
        section_index: sectionIndex + 1,
        section_title: section.title,
        card_index: cardIndex + 1,
        title: card.title,
        badge: card.badge ?? '',
        fields,
        field_count: fields.length,
        roles: roleMap[moduleId] ?? ['OTA运营人员', '店长'],
        scenes: [module.title, section.title, card.title],
        platforms: platformMap[moduleId] ?? ['ctrip', 'meituan', 'fliggy', 'other_ota'],
        task_template: {
          object_type: 'operation_checklist',
          action_type: 'execute_sop_card',
          title: card.title,
          steps: taskSteps(fields),
          acceptance_criteria: acceptanceCriteria(fields),
          human_approval_required: true,
          auto_write_ota: false,
        },
        governance: {
          threshold_policy: '来源中的时间、比例、分值和平台规则均为参考快照；执行当天须核对当前平台规则与门店基线。',
          fact_policy: '未知字段保持未知；不得用默认值、历史值或AI文字补成成功。',
          channel_scope: '仅用于对应OTA渠道运营，不扩大为全酒店经营事实。',
          credential_policy: '不保存或转交外站会员Key、Cookie、Token及可复用登录凭证。',
        },
      });
    }
    sections.push({
      seed_owner: SEED_OWNER,
      seed_version: VERSION,
      content_key: sectionKey,
      content_type: 'sop_section',
      source_snapshot_hash: sourceHash,
      source_refs: [SOURCE_URL],
      evidence_level: 'external_public_reference_reviewed',
      scope: 'ota_channel_reference_template',
      module_id: moduleId,
      module_name: module.title,
      section_index: sectionIndex + 1,
      title: section.title,
      note: section.note ?? '',
      extra: section.extra ?? '',
      card_keys: sectionCards,
      roles: roleMap[moduleId] ?? ['OTA运营人员', '店长'],
      platforms: platformMap[moduleId] ?? ['ctrip', 'meituan', 'fliggy', 'other_ota'],
    });
  }
  modules.push({
    seed_owner: SEED_OWNER,
    seed_version: VERSION,
    content_key: `${moduleId}.module`,
    content_type: 'sop_module',
    source_snapshot_hash: sourceHash,
    source_refs: [SOURCE_URL, 'https://sop.fjhoteltools.cn/'],
    evidence_level: 'external_public_reference_reviewed',
    scope: 'ota_channel_reference_template',
    module_id: moduleId,
    module_name: module.title,
    title: module.title,
    summary: module.summary ?? '',
    section_keys: moduleSectionKeys,
    roles: roleMap[moduleId] ?? ['OTA运营人员', '店长'],
    scenes: [module.title],
    platforms: platformMap[moduleId] ?? ['ctrip', 'meituan', 'fliggy', 'other_ota'],
    governance: {
      threshold_policy: '来源中的时间、比例、分值和平台规则均为参考快照；执行当天须核对当前平台规则与门店基线。',
      fact_policy: '未知字段保持未知；不得用默认值、历史值或AI文字补成成功。',
      channel_scope: '仅用于对应OTA渠道运营，不扩大为全酒店经营事实。',
      credential_policy: '不保存或转交外站会员Key、Cookie、Token及可复用登录凭证。',
    },
  });
}

const sheets = (data.modules.templates?.sheets ?? []).map((sheet, index) => ({
  seed_owner: SEED_OWNER,
  seed_version: VERSION,
  content_key: `templates.worksheet.${index + 1}.${sheet.id}`,
  content_type: 'sop_worksheet',
  source_snapshot_hash: sourceHash,
  source_refs: [SOURCE_URL],
  evidence_level: 'external_public_reference_reviewed',
  scope: 'ota_channel_reference_template',
  worksheet_id: sheet.id,
  title: sheet.title,
  headers: sheet.headers,
  rows: sheet.rows,
  roles: ['OTA运营人员', '店长', '收益经理'],
  platforms: ['ctrip', 'meituan', 'fliggy', 'other_ota'],
  instance_contract: {
    hotel_id: 'required',
    platform: 'required',
    business_date: 'required',
    owner_id: 'required',
    due_at: 'required',
    status: ['draft', 'in_progress', 'done', 'blocked'],
    evidence_refs: 'required_on_done',
    readback_verified: 'required_on_done',
  },
}));

if (modules.length !== 15 || sections.length !== 40 || cards.length !== 53 || fieldCount !== 195 || sheets.length !== 5) {
  throw new Error(`Unexpected source shape: modules=${Object.keys(data.modules).length}, sections=${sections.length}, cards=${cards.length}, fields=${fieldCount}, sheets=${sheets.length}`);
}

const markdown = ['# OTA运营SOP参考模板库', '', `来源快照：${VERSION}，SHA-256：${sourceHash}`, '', '> 仅作OTA渠道运营参考；平台规则与经验阈值必须在执行当天复核。未知字段保持未知，禁止自动写OTA。', ''];
for (const [moduleId, module] of Object.entries(data.modules)) {
  markdown.push(`## ${module.title}`, '', module.summary ?? '', '');
  for (const section of module.sections ?? []) {
    markdown.push(`### ${section.title}`, '');
    for (const card of section.cards ?? []) {
      markdown.push(`#### ${card.title}${card.badge ? `（${card.badge}）` : ''}`, '');
      for (const field of card.fields ?? []) {
        markdown.push(`- ${field.label}：${flattenText(field.content).join('；')}`);
      }
      markdown.push('');
    }
  }
}
markdown.push('## 工作表', '');
for (const sheet of sheets) markdown.push(`- ${sheet.title}：${sheet.headers.join('、')}`);

const headerLines = [
  '-- Generated by scripts/generate_ota_sop_reference_seed.mjs.',
  '-- Public reference content only; no membership key, Cookie, token or external implementation is included.',
  `-- MATERIALIZED_COUNTS modules=15 sections=40 cards=53 fields=195 worksheets=5 source_sha256=${sourceHash}`,
  '',
  'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;',
  `SET @sop_materialized_seed_owner := '${SEED_OWNER}';`,
  `SET @sop_materialized_seed_version := '${VERSION}';`,
  "SET @sop_materialized_unit_name := 'OTA运营SOP参考模板库';",
  "SET @sop_materialized_unit_source := 'ota_operation_sop_reference';",
  '',
  'INSERT INTO `knowledge_units` (`hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`, `created_at`, `updated_at`)',
  `SELECT 0, @sop_materialized_unit_name, @sop_materialized_unit_source, 'done', ${textSql('15模块、40章节、53卡片、195字段与5份工作表的OTA渠道运营参考模板；来源版本化、可检索、可转人工审批任务。')}, JSON_ARRAY('OTA运营','SOP','岗位知识','场景知识','检查表','工作表','reference_template','external_public_reference_reviewed'), 0, NOW(), NOW()`,
  'WHERE NOT EXISTS (SELECT 1 FROM `knowledge_units` WHERE `name` = @sop_materialized_unit_name AND `source` = @sop_materialized_unit_source);',
  '',
  'SET @sop_materialized_unit_id := (SELECT `unit_id` FROM `knowledge_units` WHERE `name` = @sop_materialized_unit_name AND `source` = @sop_materialized_unit_source ORDER BY `unit_id` ASC LIMIT 1);',
  '',
  'START TRANSACTION;',
  '',
  '-- __SUXIOS_SOP_APPEND__',
];

const chunkSql = (type, content) => [
    'UPDATE `knowledge_chunks`',
    `SET \`type\` = '${type}', \`content\` = ${jsonSql(content)}, \`created_by\` = 0`,
    'WHERE `unit_id` = @sop_materialized_unit_id',
    "  AND JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.seed_owner')) = @sop_materialized_seed_owner",
    "  AND JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.seed_version')) = @sop_materialized_seed_version",
    `  AND JSON_UNQUOTE(JSON_EXTRACT(\`content\`, '$.content_key')) = ${textSql(content.content_key)};`,
    '',
    'INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)',
    `SELECT @sop_materialized_unit_id, '${type}', ${jsonSql(content)}, 0, NOW()`,
    'WHERE @sop_materialized_unit_id IS NOT NULL',
    '  AND NOT EXISTS (',
    '    SELECT 1 FROM `knowledge_chunks`',
    '    WHERE `unit_id` = @sop_materialized_unit_id',
    "      AND JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.seed_owner')) = @sop_materialized_seed_owner",
    "      AND JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.seed_version')) = @sop_materialized_seed_version",
    `      AND JSON_UNQUOTE(JSON_EXTRACT(\`content\`, '$.content_key')) = ${textSql(content.content_key)}`,
    '  );',
    ''
];
const moduleBlocks = modules.map((module) => chunkSql('SOP模块', module));
const sectionBlocks = sections.map((section) => chunkSql('SOP章节', section));
const cardBlocks = cards.map((card) => chunkSql('SOP卡片', card));
const sheetBlocks = sheets.map((sheet) => chunkSql('SOP工作表', sheet));

const footerLines = [
  'INSERT INTO `knowledge_base` (`hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`, `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`)',
  `SELECT 0, 0, @sop_materialized_unit_name, ${textSql(markdown.join('\n'))}, ${textSql('OTA运营,OTA SOP,今日运营,新店接入,首次诊断,指标诊断,收益调价,页面卖点,房型价格,促销,好评,差评,周月复盘,绩效,平台规则,工作表,岗位,场景')}, JSON_ARRAY('OTA运营','SOP','检查表','工作表','reference_template'), 0, 1, 0, 0, NOW(), NOW()`,
  'WHERE NOT EXISTS (SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @sop_materialized_unit_name);',
  '',
  'UPDATE `knowledge_base`',
  `SET \`content\` = ${textSql(markdown.join('\n'))},`,
  `    \`keywords\` = ${textSql('OTA运营,OTA SOP,今日运营,新店接入,首次诊断,指标诊断,收益调价,页面卖点,房型价格,促销,好评,差评,周月复盘,绩效,平台规则,工作表,岗位,场景')},`,
  "    `tags` = JSON_ARRAY('OTA运营','SOP','检查表','工作表','reference_template'),",
  '    `is_enabled` = 1,',
  '    `update_time` = NOW()',
  'WHERE `hotel_id` = 0 AND `title` = @sop_materialized_unit_name;',
  '',
  'COMMIT;',
  ''
];

const marker = '-- __SUXIOS_SOP_APPEND__';
const flattenBlocks = (blocks) => blocks.flat().join('\n');
const requestedPart = String(process.argv.find((value) => value.startsWith('--part=')) ?? '').slice('--part='.length);
let output;
if (!requestedPart || requestedPart === 'full') {
  output = [
    ...headerLines.slice(0, -1),
    ...moduleBlocks.flat(),
    ...sectionBlocks.flat(),
    ...cardBlocks.flat(),
    ...sheetBlocks.flat(),
    ...footerLines,
  ].join('\n');
} else if (requestedPart === 'header') {
  output = headerLines.join('\n');
} else if (requestedPart === 'footer') {
  output = footerLines.join('\n');
} else {
  const match = /^(modules|sections|cards|sheets):(\d+):(\d+)$/.exec(requestedPart);
  if (!match) throw new Error(`Unsupported --part value: ${requestedPart}`);
  const [, kind, startRaw, countRaw] = match;
  const collection = kind === 'modules'
    ? moduleBlocks
    : (kind === 'sections' ? sectionBlocks : (kind === 'cards' ? cardBlocks : sheetBlocks));
  const start = Number(startRaw);
  const count = Number(countRaw);
  if (!Number.isInteger(start) || !Number.isInteger(count) || start < 0 || count <= 0 || start + count > collection.length) {
    throw new Error(`Invalid ${kind} slice ${start}:${count}; total=${collection.length}`);
  }
  output = `${flattenBlocks(collection.slice(start, start + count))}\n${marker}`;
}

const outputArgument = process.argv.find((value) => value.startsWith('--output='));
if (outputArgument) {
  writeFileSync(outputArgument.slice('--output='.length), output, 'utf8');
} else {
  process.stdout.write(output);
}
