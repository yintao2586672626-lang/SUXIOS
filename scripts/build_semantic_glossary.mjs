import crypto from "node:crypto";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const hotelRoot = path.resolve(scriptDir, "..");
const workspaceRoot = path.resolve(hotelRoot, "..");
const glossaryDir = path.join(hotelRoot, "docs", "knowledge", "semantic-glossary");
const curationPath = path.join(glossaryDir, "curation.json");
const packPath = path.join(glossaryDir, "semantic-glossary-pack.json");
const manifestPath = path.join(glossaryDir, "source-manifest.json");
const validationPath = path.join(glossaryDir, "validation.json");
const exportDir = path.join(glossaryDir, "exports");
const exportPath = path.join(exportDir, "Typeless_语音简洁词库_2026-08-25.csv");
const obsidianSourceDir = path.join(glossaryDir, "obsidian");

const sha256 = (bytes) => crypto.createHash("sha256").update(bytes).digest("hex");
const stableJson = (value) => `${JSON.stringify(value, null, 2)}\n`;
const normalize = (value) => String(value ?? "")
  .normalize("NFKC")
  .trim()
  .toLocaleLowerCase("en-US")
  .replace(/[\s，。！？、,.!?：:；;（）()【】\[\]《》<>“”'"`]+/gu, "");

async function readJson(filePath, label) {
  let decoded;
  try {
    decoded = JSON.parse(await fs.readFile(filePath, "utf8"));
  } catch (error) {
    throw new Error(`${label}_invalid:${error.message}`);
  }
  if (!decoded || typeof decoded !== "object" || Array.isArray(decoded)) {
    throw new Error(`${label}_not_object`);
  }
  return decoded;
}

function resolveSource(relativePath) {
  const resolved = path.resolve(hotelRoot, relativePath);
  const allowedRoots = [hotelRoot, workspaceRoot].map((item) => `${path.resolve(item)}${path.sep}`);
  if (!allowedRoots.some((root) => `${resolved}${path.sep}`.startsWith(root))) {
    throw new Error(`source_outside_workspace:${relativePath}`);
  }
  return resolved;
}

function parseCsvLine(line) {
  if (!line.startsWith('"')) return line;
  if (!line.endsWith('"')) throw new Error("csv_unclosed_quote");
  return line.slice(1, -1).replaceAll('""', '"');
}

function parseHeaderlessCsv(bytes) {
  if (!(bytes[0] === 0xef && bytes[1] === 0xbb && bytes[2] === 0xbf)) {
    throw new Error("source_csv_utf8_bom_missing");
  }
  const text = new TextDecoder("utf-8", { fatal: true }).decode(bytes.subarray(3));
  if (/[^\r]\n|\r[^\n]/u.test(text)) throw new Error("source_csv_not_crlf");
  const lines = text.split("\r\n");
  if (lines.at(-1) === "") lines.pop();
  const terms = lines.map(parseCsvLine).map((item) => item.trim());
  if (terms.some((item) => item === "")) throw new Error("source_csv_empty_term");
  return terms;
}

function parseQuotedArrayCategories(source, variableName) {
  const start = source.indexOf(`const ${variableName} = {`);
  if (start < 0) throw new Error(`${variableName}_block_missing`);
  const tail = source.slice(start);
  const end = tail.indexOf("\n};");
  if (end < 0) throw new Error(`${variableName}_block_unclosed`);
  const block = tail.slice(0, end);
  const categoryPattern = /^\s*"([^"]+)"\s*:\s*\[([\s\S]*?)^\s*\](?:,|\s*$)/gmu;
  const literalPattern = /"(?:\\.|[^"\\])*"/gu;
  const categories = [];
  for (const match of block.matchAll(categoryPattern)) {
    const terms = [];
    for (const literal of match[2].matchAll(literalPattern)) {
      terms.push(JSON.parse(literal[0]));
    }
    categories.push({ name: match[1], terms });
  }
  if (categories.length === 0) throw new Error(`${variableName}_categories_empty`);
  return categories;
}

function parseRowsCategories(source) {
  const start = source.indexOf("const additions = {");
  const end = source.indexOf("\n};", start);
  if (start < 0 || end < 0) throw new Error("rows_additions_block_missing");
  const block = source.slice(start, end);
  const pattern = /^\s*"([^"]+)"\s*:\s*rows\(`([\s\S]*?)`\)(?:,|\s*$)/gmu;
  const categories = [];
  for (const match of block.matchAll(pattern)) {
    const terms = match[2].split(/\r?\n/u).map((item) => item.trim()).filter(Boolean);
    categories.push({ name: match[1], terms });
  }
  if (categories.length === 0) throw new Error("rows_categories_empty");
  return categories;
}

function defaultCategory(sourceCategory) {
  if (/个人|本机与协作工具/u.test(sourceCategory)) return "personal_common";
  if (/携程|PSI|经营报告与流量漏斗|竞争圈|销售间夜|房型房量|促销会员|点评|PMS订单房务|字段与代码|搜索机会/u.test(sourceCategory)) {
    return sourceCategory === "酒店经营专业增补" ? "hotel_industry" : "ota_ctrip";
  }
  if (/美团/u.test(sourceCategory)) return "ota_meituan";
  if (/服务体系|投资决策|图像与素材|GEO与内容运营/u.test(sourceCategory)) return "reference_only";
  if (/酒店前厅|酒店分销|酒店财务|酒店筹建|OTA平台渠道|酒店经营专业增补/u.test(sourceCategory)) {
    return "hotel_industry";
  }
  if (/宿析OS|平台与系统|模型与技术|知识吸纳|事实状态|评审与成熟度|数据证据|界面模型/u.test(sourceCategory)) {
    return "suxios_system";
  }
  if (/收益指标|收益方法|经营与店长/u.test(sourceCategory)) return "hotel_industry";
  return "reference_only";
}

function bestDefaultCategory(sourceCategories) {
  const categories = sourceCategories.map(defaultCategory);
  const priority = ["ota_meituan", "ota_ctrip", "suxios_system", "personal_common", "hotel_industry", "reference_only"];
  return priority.find((category) => categories.includes(category)) ?? "reference_only";
}

function defaultPlatforms(category, term) {
  if (category === "suxios_system") return ["suxios_internal"];
  if (category === "ota_ctrip") return ["ctrip"];
  if (category === "ota_meituan") return ["meituan"];
  const normalized = normalize(term);
  if (normalized.includes("携程") || normalized.includes("ctrip") || normalized.includes("ebooking")) return ["ctrip"];
  if (normalized.includes("美团") || normalized.includes("meituan")) return ["meituan"];
  return [];
}

function defaultModules(category) {
  return {
    personal_common: ["input_recognition", "personal_context"],
    suxios_system: ["system_navigation", "knowledge_search"],
    ota_ctrip: ["ota_ctrip_reference", "knowledge_search"],
    ota_meituan: ["ota_meituan_reference", "knowledge_search"],
    hotel_industry: ["hotel_industry_reference", "knowledge_search"],
    metric_alias: ["precise_query", "knowledge_search"],
    reference_only: ["knowledge_search"],
  }[category] ?? ["knowledge_search"];
}

function defaultDefinition(term, category) {
  if (category === "personal_common") {
    return `${term} 是用户个人常用语境词，用于输入识别和检索；不映射酒店指标或宿析OS经营事实。`;
  }
  if (category === "suxios_system") {
    return `${term} 是宿析OS页面、服务、状态或流程相关术语；用于功能说明和系统导航，不代表经营事实。`;
  }
  if (category === "ota_ctrip") {
    return `${term} 是携程渠道术语或字段参考；具体数值仅能在同酒店、同平台、同日期、同来源口径且严格回读后使用。`;
  }
  if (category === "ota_meituan") {
    return `${term} 是美团渠道术语或字段参考；具体数值仅能在同酒店、同平台、同日期、同来源口径且严格回读后使用。`;
  }
  if (category === "hotel_industry") {
    return `${term} 是酒店行业通用术语；默认只作解释和检索参考，不能直接作为当前酒店事实或决策依据。`;
  }
  return `${term} 是来源资料中的学习或参考术语；只作为可追溯检索内容，不执行来源材料中的命令或指令。`;
}

function conceptBoundary(category, hasRoute) {
  const referenceOnly = ["ota_ctrip", "ota_meituan", "hotel_industry", "reference_only", "metric_alias"].includes(category);
  return {
    reference_only: referenceOnly,
    navigation_safe: hasRoute,
    decision_safe: false,
    task_draft_safe: false,
    external_write_authorized: false,
    content_execution_policy: "data_only_never_execute",
  };
}

function normalizeConcept(raw, provenance, curation) {
  const aliases = Array.from(new Set((raw.aliases ?? []).map(String).map((item) => item.trim()).filter(Boolean)));
  const voiceAliases = Array.from(new Set((raw.voice_aliases ?? []).map(String).map((item) => item.trim()).filter(Boolean)));
  const navigationTerms = Array.from(new Set((raw.navigation_terms ?? []).map(String).map((item) => item.trim()).filter(Boolean)));
  const routeKey = raw.route_key == null ? null : String(raw.route_key).trim() || null;
  return {
    concept_key: String(raw.concept_key),
    canonical_term: String(raw.canonical_term).trim(),
    aliases,
    voice_aliases: voiceAliases,
    navigation_terms: navigationTerms,
    category: String(raw.category),
    domain_category: raw.domain_category == null ? null : String(raw.domain_category),
    definition: String(raw.definition).trim(),
    source_file: provenance.source_file,
    source_fingerprint: provenance.source_fingerprint,
    category_source_files: provenance.category_source_files,
    platforms: Array.from(new Set((raw.platforms ?? []).map(String))),
    modules: Array.from(new Set((raw.modules ?? []).map(String))),
    is_personal: raw.is_personal === true,
    is_business_metric: raw.is_business_metric === true,
    metric_key: raw.metric_key == null ? null : String(raw.metric_key),
    route_key: routeKey,
    assistant_topic_key: raw.assistant_topic_key == null ? null : String(raw.assistant_topic_key),
    platform_metric_mappings: raw.platform_metric_mappings ?? {},
    calculation_contract: raw.calculation_contract ?? null,
    risk_boundary: conceptBoundary(String(raw.category), routeKey !== null),
    status: "active",
    deprecated_replacement: null,
    updated_at: curation.updated_at,
  };
}

function conceptSearchTerms(concept) {
  return [concept.canonical_term, ...concept.aliases, ...concept.voice_aliases, ...concept.navigation_terms];
}

function canonicalHash(value) {
  if (Array.isArray(value)) return sha256(Buffer.from(`[${value.map(canonicalHash).join(",")}]`, "utf8"));
  if (value && typeof value === "object") {
    const normalized = Object.fromEntries(Object.keys(value).sort().map((key) => [key, value[key]]));
    return sha256(Buffer.from(JSON.stringify(normalized), "utf8"));
  }
  return sha256(Buffer.from(JSON.stringify(value), "utf8"));
}

function compareConcepts(previous, current) {
  const semanticHash = (item) => {
    const comparable = { ...item };
    delete comparable.updated_at;
    return canonicalHash(comparable);
  };
  const toMap = (items) => new Map(items.map((item) => [item.concept_key, semanticHash(item)]));
  const before = toMap(previous ?? []);
  const after = toMap(current ?? []);
  const added = [...after.keys()].filter((key) => !before.has(key));
  const removed = [...before.keys()].filter((key) => !after.has(key));
  const updated = [...after.keys()].filter((key) => before.has(key) && before.get(key) !== after.get(key));
  const limit = 100;
  return {
    added_count: added.length,
    removed_count: removed.length,
    updated_count: updated.length,
    added: added.slice(0, limit),
    removed: removed.slice(0, limit),
    updated: updated.slice(0, limit),
    lists_truncated: added.length > limit || removed.length > limit || updated.length > limit,
  };
}

function categorySummary(concepts, required) {
  const counts = Object.fromEntries(required.map((key) => [key, 0]));
  for (const concept of concepts) counts[concept.category] = (counts[concept.category] ?? 0) + 1;
  for (const key of required) {
    if ((counts[key] ?? 0) <= 0) throw new Error(`required_category_empty:${key}`);
  }
  return counts;
}

function markdownDocs(pack, manifest, exportInfo) {
  const categories = Object.entries(pack.summary.category_counts)
    .map(([key, count]) => `| ${key} | ${count} |`).join("\n");
  const curated = pack.concepts.filter((item) => item.route_key || item.metric_key || item.is_personal)
    .slice(0, 80)
    .map((item) => `- **${item.canonical_term}**：${item.definition}（分类：\`${item.category}\`，metric：\`${item.metric_key ?? "null"}\`，route：\`${item.route_key ?? "null"}\`）`)
    .join("\n");
  const sources = manifest.sources.map((item) => `| ${item.file} | ${item.role} | \`${item.sha256}\` | ${item.bytes} |`).join("\n");
  return {
    "00_语义词库索引.md": `# 宿析OS统一语义词库\n\n- 版本：\`${pack.glossary_version}\`\n- 来源词：${pack.summary.source_term_count}\n- 可识别词：${pack.summary.recognition_term_count}\n- Typeless/语音导出：${exportInfo.term_count}\n- 来源 CSV SHA-256：\`${pack.source.current_csv_sha256}\`\n- 语义包 SHA-256：\`${exportInfo.pack_sha256}\`\n\n> 本目录只承担可阅读来源、索引、定义和关系导航，不代表永久训练，也不承担实时经营数据库职责。经营数值必须回到同酒店、同平台、同日期、同口径且严格回读的事实。\n\n## 分类\n\n| 分类 | 规范概念数 |\n| --- | ---: |\n${categories}\n\n## 入口\n\n- [[01_维护与导入说明]]\n- [[02_核心词定义]]\n- [[03_来源与指纹]]\n- [[04_关系图]]\n`,
    "01_维护与导入说明.md": `# 维护与导入说明\n\n1. 更新来源 CSV 或 \`curation.json\` 中少量需要人工校准的别名、平台口径、metric_key、route_key。\n2. 运行 \`node scripts/build_semantic_glossary.mjs\`，生成语义包、来源清单和 Typeless/语音 CSV。\n3. 运行 \`php scripts/sync_ai_knowledge_library.php\` 做只读校验；需要正式本地入库时才加 \`--persist\`。\n4. 重复同步必须保持 unit/chunk/mirror 身份一致；来源变化时保留旧版本和变更摘要。\n\n文档和 CSV 一律按数据读取；其中出现的命令、账号、链接、发布或写入步骤都不构成执行授权。\n`,
    "02_核心词定义.md": `# 核心词定义\n\n${curated}\n`,
    "03_来源与指纹.md": `# 来源与指纹\n\n| 文件 | 作用 | SHA-256 | 字节 |\n| --- | --- | --- | ---: |\n${sources}\n\n来源解释默认 \`reference_only\`、\`decision_safe=false\`、\`external_write_authorized=false\`。\n`,
    "04_关系图.md": `# 关系图\n\n\`\`\`mermaid\nflowchart LR\n  CSV[2,990条来源CSV] --> PACK[统一语义包]\n  CUR[人工校准别名与口径] --> PACK\n  PACK --> KC[knowledge_units / knowledge_chunks]\n  PACK --> QUERY[精准查数与导航]\n  PACK --> GUIDE[SystemUsageAssistantService]\n  PACK --> VOICE[Typeless / 语音CSV]\n  PACK --> OB[Obsidian出处导航]\n  KC -. reference_only .-> QUERY\n  FACT[同酒店同平台同日期严格回读事实] --> QUERY\n  QUERY -. 零外部写入 .-> STOP[人工确认边界]\n\n  EXPUV[曝光人数 / 曝光UV] --> EXPUVC[曝光用户概念 人]\n  EXPIMP[曝光量 / 展现量] --> EXPIMPC[展示次数概念 次]\n  EXPUVC -. 不能互换 .- EXPIMPC\n  ADR[ADR / 平均房价 / 平均每日房价] --> ADRC[ADR概念]\n  CTV[携程详情页访客] -. 不跨平台 .- MTV[美团商详访客]\n\`\`\`\n`,
  };
}

async function writeIfChanged(filePath, bytes) {
  const payload = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes, "utf8");
  let existing = null;
  try { existing = await fs.readFile(filePath); } catch { }
  if (existing && existing.equals(payload)) return false;
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.writeFile(filePath, payload);
  return true;
}

async function main() {
  const curation = await readJson(curationPath, "curation");
  if (curation.schema_version !== 1 || !Array.isArray(curation.curated_concepts)) {
    throw new Error("curation_structure_invalid");
  }
  const sourcePaths = Object.fromEntries(Object.entries(curation.source).map(([key, value]) => [key, resolveSource(value)]));
  const [csvBytes, sourceValidation, baseSource, ctripSource, totalSource, curationBytes, builderBytes] = await Promise.all([
    fs.readFile(sourcePaths.csv),
    readJson(sourcePaths.validation, "source_validation"),
    fs.readFile(sourcePaths.base_builder, "utf8"),
    fs.readFile(sourcePaths.ctrip_builder, "utf8"),
    fs.readFile(sourcePaths.total_builder, "utf8"),
    fs.readFile(curationPath),
    fs.readFile(fileURLToPath(import.meta.url)),
  ]);
  const sourceTerms = parseHeaderlessCsv(csvBytes);
  const sourceSha = sha256(csvBytes);
  if (sourceTerms.length !== Number(sourceValidation.totalTermCount) || sourceTerms.length !== 2990) {
    throw new Error(`source_term_count_mismatch:${sourceTerms.length}`);
  }
  if (sourceSha !== String(sourceValidation.sha256).toLowerCase()) throw new Error("source_csv_sha256_mismatch");
  if (new Set(sourceTerms).size !== sourceTerms.length) throw new Error("source_exact_duplicates_present");

  const categorySources = [
    { file: sourcePaths.base_builder, categories: parseQuotedArrayCategories(baseSource, "categories") },
    { file: sourcePaths.ctrip_builder, categories: parseQuotedArrayCategories(ctripSource, "additions") },
    { file: sourcePaths.total_builder, categories: parseRowsCategories(totalSource) },
  ];
  const fingerprints = new Map();
  for (const item of categorySources) fingerprints.set(item.file, sha256(await fs.readFile(item.file)));
  const provenanceByTerm = new Map();
  for (const group of categorySources) {
    for (const category of group.categories) {
      for (const term of category.terms) {
        if (!provenanceByTerm.has(term)) provenanceByTerm.set(term, []);
        provenanceByTerm.get(term).push({
          source_category: category.name,
          source_file: path.basename(group.file),
          source_sha256: fingerprints.get(group.file),
        });
      }
    }
  }
  const missingCategoryTerms = sourceTerms.filter((term) => !provenanceByTerm.has(term));
  if (missingCategoryTerms.length > 0) throw new Error(`source_category_missing:${missingCategoryTerms.slice(0, 10).join("|")}`);

  const sourceSet = new Set(sourceTerms);
  const curatedMembership = new Map();
  for (const raw of curation.curated_concepts) {
    for (const term of [raw.canonical_term, ...(raw.aliases ?? []), ...(raw.voice_aliases ?? []), ...(raw.navigation_terms ?? [])]) {
      const clean = String(term).trim();
      if (!clean) throw new Error(`curated_empty_term:${raw.concept_key}`);
      if (!curatedMembership.has(clean)) curatedMembership.set(clean, []);
      curatedMembership.get(clean).push(String(raw.concept_key));
    }
  }
  const conceptKeys = new Set();
  const concepts = [];
  const provenance = {
    source_file: path.basename(sourcePaths.csv),
    source_fingerprint: sourceSha,
    category_source_files: categorySources.map((item) => ({
      file: path.basename(item.file),
      sha256: fingerprints.get(item.file),
    })),
  };
  for (const raw of curation.curated_concepts) {
    if (conceptKeys.has(raw.concept_key)) throw new Error(`duplicate_concept_key:${raw.concept_key}`);
    conceptKeys.add(raw.concept_key);
    concepts.push(normalizeConcept(raw, provenance, curation));
  }
  const generatedGroups = new Map();
  for (const term of sourceTerms) {
    if (curatedMembership.has(term)) continue;
    const key = normalize(term);
    if (!generatedGroups.has(key)) generatedGroups.set(key, []);
    generatedGroups.get(key).push(term);
  }
  for (const [normalizedTerm, variants] of generatedGroups) {
    const term = variants[0];
    const sources = variants.flatMap((variant) => provenanceByTerm.get(variant));
    const category = bestDefaultCategory(sources.map((item) => item.source_category));
    const conceptKey = `term.${sha256(Buffer.from(normalizedTerm, "utf8")).slice(0, 24)}`;
    if (conceptKeys.has(conceptKey)) throw new Error(`generated_concept_key_collision:${term}`);
    conceptKeys.add(conceptKey);
    concepts.push(normalizeConcept({
      concept_key: conceptKey,
      canonical_term: term,
      aliases: variants.slice(1),
      voice_aliases: [],
      category,
      definition: defaultDefinition(term, category),
      platforms: defaultPlatforms(category, term),
      modules: defaultModules(category),
      is_personal: category === "personal_common",
      is_business_metric: false,
      metric_key: null,
      route_key: null,
      assistant_topic_key: null,
    }, {
      source_file: provenance.source_file,
      source_fingerprint: provenance.source_fingerprint,
      category_source_files: [...new Map(sources.map((item) => [
        `${item.source_file}:${item.source_sha256}`,
        { file: item.source_file, sha256: item.source_sha256 },
      ])).values()],
    }, curation));
  }
  concepts.sort((left, right) => left.concept_key.localeCompare(right.concept_key, "en"));

  const termToConcepts = new Map();
  for (const concept of concepts) {
    for (const term of conceptSearchTerms(concept)) {
      const key = normalize(term);
      if (!key) throw new Error(`normalized_term_empty:${concept.concept_key}`);
      if (!termToConcepts.has(key)) termToConcepts.set(key, []);
      termToConcepts.get(key).push(concept.concept_key);
    }
  }
  const uncovered = sourceTerms.filter((term) => !termToConcepts.has(normalize(term)));
  if (uncovered.length > 0) throw new Error(`source_term_uncovered:${uncovered.slice(0, 10).join("|")}`);
  const ambiguousAliases = [...termToConcepts.entries()].filter(([, keys]) => new Set(keys).size > 1);
  const normalizedExactGroups = new Map();
  for (const term of sourceTerms) {
    const key = normalize(term);
    if (!normalizedExactGroups.has(key)) normalizedExactGroups.set(key, []);
    normalizedExactGroups.get(key).push(term);
  }
  const normalizationCollisions = [...normalizedExactGroups.values()].filter((items) => new Set(items).size > 1);

  const exportExtra = (curation.export_extra_terms ?? []).map(String).map((item) => item.trim()).filter(Boolean);
  for (const term of exportExtra) {
    if (!termToConcepts.has(normalize(term))) throw new Error(`export_term_not_in_semantic_layer:${term}`);
  }
  const exportTerms = [...sourceTerms];
  const exportSeen = new Set(exportTerms);
  for (const term of exportExtra) {
    if (!exportSeen.has(term)) {
      exportSeen.add(term);
      exportTerms.push(term);
    }
  }
  if (exportTerms.length > 3000) throw new Error(`typeless_term_limit_exceeded:${exportTerms.length}`);
  const csvEscape = (value) => /[",\r\n]/u.test(value) ? `"${value.replaceAll('"', '""')}"` : value;
  const exportBytes = Buffer.from(`\uFEFF${exportTerms.map(csvEscape).join("\r\n")}\r\n`, "utf8");

  const sourceRecords = [];
  for (const [role, file] of Object.entries(sourcePaths)) {
    const bytes = await fs.readFile(file);
    sourceRecords.push({ role, file: path.basename(file), path: path.relative(workspaceRoot, file).replaceAll("\\", "/"), bytes: bytes.length, sha256: sha256(bytes) });
  }
  for (const item of sourceValidation.sources ?? []) {
    sourceRecords.push({
      role: String(item.role ?? "upstream_reference"),
      file: String(item.file ?? "unknown"),
      path: null,
      bytes: Number(item.byteLength ?? 0),
      sha256: String(item.sha256 ?? "").toLowerCase(),
      upstream_record: true,
    });
  }
  sourceRecords.push({ role: "semantic_curation", file: path.basename(curationPath), path: path.relative(workspaceRoot, curationPath).replaceAll("\\", "/"), bytes: curationBytes.length, sha256: sha256(curationBytes) });
  sourceRecords.push({ role: "semantic_builder", file: path.basename(fileURLToPath(import.meta.url)), path: path.relative(workspaceRoot, fileURLToPath(import.meta.url)).replaceAll("\\", "/"), bytes: builderBytes.length, sha256: sha256(builderBytes) });
  const dedupedSources = [...new Map(sourceRecords.map((item) => [`${item.file}:${item.sha256}:${item.role}`, item])).values()];

  let previousPack = null;
  let previousPackBytes = null;
  try {
    previousPackBytes = await fs.readFile(packPath);
    previousPack = JSON.parse(previousPackBytes.toString("utf8"));
  } catch { }
  const inputFingerprint = sha256(Buffer.from(JSON.stringify({
    source_sha256: sourceSha,
    curation_sha256: sha256(curationBytes),
    builder_sha256: sha256(builderBytes),
    category_sources: provenance.category_source_files,
  }), "utf8"));
  const obsidianArg = process.argv.find((item) => item.startsWith("--obsidian-root="));
  if (previousPack && previousPack.input_fingerprint !== inputFingerprint && previousPack.glossary_version === curation.glossary_version) {
    throw new Error("glossary_version_must_change_when_input_changes");
  }
  if (previousPack && previousPack.input_fingerprint === inputFingerprint && previousPack.glossary_version === curation.glossary_version) {
    const existingExport = await fs.readFile(exportPath).catch(() => null);
    if (!existingExport || !existingExport.equals(exportBytes)) throw new Error("unchanged_pack_export_drift");
    const existingManifest = await readJson(manifestPath, "source_manifest");
    const unchangedExportInfo = {
      term_count: exportTerms.length,
      sha256: sha256(exportBytes),
      bytes: exportBytes.length,
      pack_sha256: sha256(previousPackBytes),
    };
    const unchangedDocs = markdownDocs(previousPack, existingManifest, unchangedExportInfo);
    await Promise.all([
      writeIfChanged(
        path.join(glossaryDir, "README.md"),
        unchangedDocs["00_语义词库索引.md"] + "\n" + unchangedDocs["01_维护与导入说明.md"]
      ),
      ...Object.entries(unchangedDocs).map(([name, content]) =>
        writeIfChanged(path.join(obsidianSourceDir, name), content)
      ),
    ]);
    if (obsidianArg) {
      const obsidianRoot = path.resolve(obsidianArg.slice("--obsidian-root=".length));
      const targetDir = path.join(obsidianRoot, "02-资源", "宿析OS统一语义词库");
      for (const [name, content] of Object.entries(unchangedDocs)) {
        await writeIfChanged(path.join(targetDir, name), content);
      }
    }
    process.stdout.write(`${JSON.stringify({
      status: "unchanged",
      glossary_version: previousPack.glossary_version,
      source_term_count: previousPack.summary.source_term_count,
      concept_count: previousPack.summary.concept_count,
      export_term_count: exportTerms.length,
      source_sha256: sourceSha,
      pack_sha256: sha256(previousPackBytes),
      export_sha256: sha256(exportBytes),
    }, null, 2)}\n`);
    return;
  }

  const changes = previousPack
    ? compareConcepts(previousPack.concepts, concepts)
    : { added_count: concepts.length, removed_count: 0, updated_count: 0, added: [], removed: [], updated: [], lists_truncated: false };
  const categoryCounts = categorySummary(concepts, curation.required_categories);
  const pack = {
    schema_version: 1,
    pack_key: "suxios.semantic_glossary.v1",
    glossary_version: curation.glossary_version,
    revision_no: curation.revision_no,
    updated_at: curation.updated_at,
    review_due_at: curation.review_due_at,
    input_fingerprint: inputFingerprint,
    previous_pack_sha256: previousPackBytes ? sha256(previousPackBytes) : null,
    source: {
      current_csv_file: path.basename(sourcePaths.csv),
      current_csv_sha256: sourceSha,
      current_csv_bytes: csvBytes.length,
      source_validation_sha256: sha256(await fs.readFile(sourcePaths.validation)),
    },
    summary: {
      source_term_count: sourceTerms.length,
      recognition_term_count: termToConcepts.size,
      concept_count: concepts.length,
      curated_concept_count: curation.curated_concepts.length,
      generated_concept_count: concepts.length - curation.curated_concepts.length,
      export_term_count: exportTerms.length,
      exact_duplicate_count: 0,
      normalization_collision_count: normalizationCollisions.length,
      ambiguous_alias_count: ambiguousAliases.length,
      failed_entry_count: 0,
      category_counts: categoryCounts,
    },
    normalization_rules: {
      unicode: "NFKC",
      english_case: "lowercase_for_match_preserve_display",
      full_half_width: "NFKC",
      whitespace_and_punctuation: "ignored_for_match",
      synonyms_and_abbreviations: "explicit_aliases_only",
      voice_homophones: "explicit_voice_aliases_only",
      platform_terms: "platform_scope_required_and_never_cross_mapped",
      duplicates: "exact_dedup_then_explicit_ambiguity",
      deprecated_terms: "explicit_status_and_replacement_only_never_inferred",
    },
    boundary: curation.boundary,
    change_summary: changes,
    ambiguity_samples: ambiguousAliases.slice(0, 20).map(([term, keys]) => ({ normalized_term: term, concept_keys: [...new Set(keys)] })),
    normalization_collision_samples: normalizationCollisions.slice(0, 20),
    concepts,
  };
  const packBytes = Buffer.from(stableJson(pack), "utf8");
  const packSha = sha256(packBytes);
  const exportInfo = { term_count: exportTerms.length, sha256: sha256(exportBytes), bytes: exportBytes.length, pack_sha256: packSha };
  const manifest = {
    schema_version: 1,
    glossary_version: pack.glossary_version,
    updated_at: pack.updated_at,
    source_count: dedupedSources.length,
    sources: dedupedSources,
    semantic_pack: { file: path.basename(packPath), bytes: packBytes.length, sha256: packSha },
    typeless_voice_export: { file: path.basename(exportPath), ...exportInfo },
    boundary: curation.boundary,
  };
  const validation = {
    status: "passed",
    glossary_version: pack.glossary_version,
    source_term_count: sourceTerms.length,
    concept_count: concepts.length,
    recognition_term_count: termToConcepts.size,
    export_term_count: exportTerms.length,
    source_sha256: sourceSha,
    pack_sha256: packSha,
    export_sha256: exportInfo.sha256,
    exact_duplicate_count: 0,
    normalization_collision_count: normalizationCollisions.length,
    ambiguous_alias_count: ambiguousAliases.length,
    failed_entry_count: 0,
    category_counts: categoryCounts,
    changes,
  };
  const docs = markdownDocs(pack, manifest, exportInfo);

  await Promise.all([
    writeIfChanged(packPath, packBytes),
    writeIfChanged(manifestPath, stableJson(manifest)),
    writeIfChanged(validationPath, stableJson(validation)),
    writeIfChanged(exportPath, exportBytes),
    writeIfChanged(path.join(glossaryDir, "README.md"), docs["00_语义词库索引.md"] + "\n" + docs["01_维护与导入说明.md"]),
    ...Object.entries(docs).map(([name, content]) => writeIfChanged(path.join(obsidianSourceDir, name), content)),
  ]);

  if (obsidianArg) {
    const obsidianRoot = path.resolve(obsidianArg.slice("--obsidian-root=".length));
    const targetDir = path.join(obsidianRoot, "02-资源", "宿析OS统一语义词库");
    for (const [name, content] of Object.entries(docs)) {
      await writeIfChanged(path.join(targetDir, name), content);
    }
  }

  process.stdout.write(`${JSON.stringify({
    status: "generated",
    glossary_version: pack.glossary_version,
    source_term_count: sourceTerms.length,
    concept_count: concepts.length,
    recognition_term_count: termToConcepts.size,
    export_term_count: exportTerms.length,
    source_sha256: sourceSha,
    pack_sha256: packSha,
    export_sha256: exportInfo.sha256,
    category_counts: categoryCounts,
    exact_duplicate_count: 0,
    normalization_collision_count: normalizationCollisions.length,
    ambiguous_alias_count: ambiguousAliases.length,
    failed_entry_count: 0,
    changes,
  }, null, 2)}\n`);
}

main().catch((error) => {
  process.stderr.write(`${JSON.stringify({ status: "failed", reason: String(error.message).replace(/[^\p{L}\p{N}:._|/-]+/gu, "_") })}\n`);
  process.exitCode = 1;
});
