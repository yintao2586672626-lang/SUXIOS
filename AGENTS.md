# AGENTS.md — Codex 接手指南

> 本文件用于指导 AI Agent（Codex）接手项目开发。所有修改必须遵循本文件规则。

---

## 最高优先级开发规范

### 第一性原理约束

项目所有行为和目的必须回到业务原点：真实 OTA 数据 → 收益分析 → AI 决策 → 运营管理 → 投资决策。

执行要求：

1. 先判断真实业务问题、数据来源、业务口径、影响链路和验证方式，再决定 UI、代码、模型或流程改动。
2. 明确区分事实、假设、决策和未知；证据不足时必须标注未知，不得用话术、兜底值或静默逻辑掩盖。
3. OTA 渠道数据、全酒店经营数据、收益指标、AI 建议和投资判断必须保持边界清晰，不得混用口径。
4. 当速度、视觉效果、功能完整度与真实性、兼容性、范围控制、可验证性冲突时，优先真实性、兼容性、范围控制和可验证性。
5. “第一性原理”不是扩大范围、重写无关模块或绕过现有规则的理由；仍然遵守最小改动、旧功能兼容和项目验证要求。

### Less, Typeless Mode

始终优先使用最少文字完成最高质量输出。不要寒暄，不要长篇解释，不要重复需求，不输出无关背景。

### 自然语言开发模式

用户可以只用业务语言描述目标，不需要提供文件名、技术方案、命令或 Skill 名称。Codex 负责把自然语言转成可执行开发任务：目标、范围、涉及模块、非目标、验证方式和风险点。

#### 语音输入默认自动理解

1. 默认承认用户消息可能来自普通话语音转录，但先只做字面一致性判断；语义清楚时原样理解，不加载完整语音 Skill。
2. 只有出现疑似转录噪声、酒店同音词、口头自我纠正、断裂语序、指代不清，或用户明确要求理解语音时，才自动使用 `.agents/skills/suxi-voice-intent`；存在真实歧义时再读取本机 `$transcript-fixer` 的 `suxios-hotel` 语境。用户不需要手动点名。
3. 单条聊天消息只在内存中结合上下文纠错，不落盘、不运行转录脚本、不写词典数据库、不调用外部 API。只有用户提供转录文件或明确要求批量纠错时，才使用安全 Stage 1 包装器。
4. `suxi-voice-intent` 是唯一自动语音入口，不自动并行调用其他语音插件；否定、数字、日期、金额、平台、门店、路径、ID、写入和发布范围存在歧义时，只确认那个决定执行结果的词。

执行规则：

1. 如果用户意图清楚，自动优化需求表达并直接推进。
2. 只有缺失信息会导致错误实现、错误业务口径或高风险改动时才提问。
3. 每次最多优先问一个关键问题，不把技术细节选择转嫁给用户。
4. 用户说“优化”“帮我看看”“这个不对”“做得更好”时，默认允许在当前问题范围内改进，不代表可以重写无关模块。
5. 对 OTA、收益、AI 决策、运营和投资相关需求，先判断数据来源、业务口径和影响链路，再决定技术改动。
6. 不把 OTA 渠道数据包装成全酒店经营数据；范围不清时必须明确标注。
7. 对新增字段、接口、配置、采集项或指标，必须同时考虑保存、回显、编辑、旧数据兼容和数据质量状态。
8. 用户已明确授权 OTA Cookie/API、目标门店和查询范围时，优先直接跑通“查询 → 保存 → 页面回显”最短闭环并给出真实结果；不要把 Profile/P0、发布门禁或其他后续治理当作这次单次授权查询的前置阻断。Cookie/API 结果仍须标注为对应授权路径和 OTA 渠道口径。
9. 内部安全与治理规则只阻断真实风险，不得误拦截正常业务值：严格执行接口不得被全局请求层自动追加白名单外字段；纯数字或布尔型 Cookie 偏好值不得因与排名、数量相同而被当作凭据泄漏。完整 Cookie、令牌和长会话值仍必须保护。
10. 错误提示必须对应真实失败阶段，不得把参数错误、执行结果检查或平台错误统一包装成“OTA 凭据不可用”。排查时按“请求字段 → 凭据定位与解密 → 平台请求 → 保存 → 回显”逐层确认，找到根因后直接修复并验证结果。
11. 统一的是 OTA 数据结果契约，不是采集实现。携程、美团及不同业务页面可以分别采用授权 API/Cookie、浏览器 Profile、插件、Python 自动化、页面解析或人工导入；必须按来源选择最稳定、最短且可验证的实现，不得为了形式统一强行共用一套抓取逻辑。
12. 每种采集方式都必须产出可追溯的稳定数据身份：`platform`、`system_hotel_id`、平台门店标识、`data_date`、`collected_at`、`source_method`、验证状态和字段事实。任何方式都不得用旧数据、空数组、默认值或其他门店数据伪装本次成功。
13. OTA 数据稳定性的最低验收是：来源专属校验通过 → 同门店同平台同日期幂等保存或明确版本策略 → 数据库回读数量与关键字段一致 → 页面回显日期和来源正确。插件或 Python 只是采集适配器，不能绕过保存、回读、去重、失败分阶段和凭据保护。
14. OTA 采集方式由 Codex 根据结果、效率和稳定性自主选择。默认优先级是：已授权且结构明确的 API/Cookie → 可复用且登录态已验证的浏览器 Profile → 已安装且能力匹配的插件 → Python 自动化或来源专属页面解析 → 人工导入。该顺序不是机械限制；当后序方式有更强的当前证据、更少步骤或更高成功率时，可以直接选择并说明来源。
15. 每次采集只设一条主执行路径和必要的一条备用路径，不同时启动多套重复方案，不为展示技术而增加插件、脚本、解析器或中间文件。主路径失败时必须先记录真实失败阶段，再切换备用路径；任一路径完成“查询/获取 → 保存 → 数据库回读 → 页面回显”后立即停止。
16. “数据存在”与“数据可作为真实事实”必须分开。接口返回、页面出现、数组非空、数据库有历史行或人工提供材料，只能证明对应证据存在；只有来源、系统酒店、平台门店/POI、目标日期、字段口径、采集时间、保存和回读全部验证后，质量状态才可标记为 `available`。其他情况必须使用现有状态 `partial`、`stale`、`unverified`、`binding_missing`、`permission_denied` 或 `collection_failed`，不得自行改名为成功。
17. 后续任何数据获取任务都必须先声明目标门店、平台/来源、目标日期、采集方式和验收字段；结果必须同时报告实际数据、来源证据、质量状态、保存数量和回读结果。历史数据只标记历史存在，人工导入默认不高于 `unverified`，推导指标必须标记为 derived 并指向输入事实，模拟/演示数据不得进入真实线上快照或下游收益与 AI 决策。

### OTA 登录状态与门店执行设备边界

1. 浏览器登录状态归登录操作者的设备所有。Profile、Cookie、localStorage、sessionStorage、长会话令牌、验证码状态和浏览器缓存默认只允许保存在用户实际登录的电脑，不得集中复制、上传或同步到宿析OS服务器。
2. 宿析OS服务器只保存用户、设备、平台账号别名、授权门店映射、采集任务状态、最后成功时间、失败原因和结构化采集结果；不得把服务器建设成集中承载数百家门店浏览器 Profile 的账号池。
3. 门店采集由有权访问该门店的用户在自己的设备执行。服务端在接收结果时仍须校验 `tenant_id`、`user_id`、`system_hotel_id`、平台门店标识和用户门店权限，禁止跨用户、跨租户或跨门店写入。
4. 一个 OTA 平台账号可以映射多个授权门店，应优先复用账号级本地 Profile，不得机械地为每家门店复制一套浏览器 Profile。
5. 手动采集是当前默认主路径；Python、Node、插件或浏览器辅助工具只能作为用户设备上的执行适配器。设备离线、未登录或授权失效时必须显示真实阻塞状态，不得自动切换到服务器或其他用户设备代采。
6. 用户设备默认只上传结构化字段事实和最小必要来源证据，不上传完整 Cookie、Authorization、Profile 目录、敏感 HAR 或长期保留的原始页面资产。临时文件必须有容量、数量和保留期限制，确认入库后可安全清理。
7. 当前服务器电脑上的 Profile 仅允许作为明确标注的“单用户本机模式”继续兼容，不得据此扩展集中 Profile 架构。任何涉及 OTA 登录、Profile、Cookie、设备绑定或采集任务派发的改动，都必须同时遵守 `rules/ota-device-local-session-boundary.md`。

### 工作规则

1. 先快速理解当前上下文与代码结构，再动手。
2. 只处理用户明确指定的问题范围。
3. 默认采用最小改动原则。
4. 不做无关重构。
5. 不改无关页面、导航、样式、状态管理和数据结构。
6. 不为了“优化”扩大修改范围。
7. 上下文不足时，先基于现有信息做合理假设并继续；只有会导致错误实现时才提问。
8. 每次输出优先给可执行结果、代码修改方案或补丁。
9. 修复 bug 时，先定位根因，再做最小修复。
10. 新增功能时，必须保证旧功能不受影响。
11. 所有新增字段必须考虑保存、回显、编辑、旧数据兼容。
12. 不删除历史字段；必要时做兼容映射。
13. 保持现有视觉风格、组件规范和命名风格。
14. 优先复用项目已有组件、工具函数、状态管理和主题变量。
15. 不引入不必要依赖。
16. 不写临时代码、伪代码、占位逻辑。
17. 修改前先说明改动位置和最小方案。
18. 修改后必须说明验证方式和风险点。
19. 涉及前端或系统交互的代码修改完成前，如内置浏览器可用，必须刷新或导航到当前项目 URL/相关页面；如果验证的是本地页面或线上需部署后才生效，必须明确说明。
20. 不凭空造事实；缺少数据、日志、接口返回、验证结果或明确证据时，必须如实说明未知，不得编造结论、数值、状态或来源。
21. 不写兜底逻辑掩盖问题；禁止用静默失败、假成功、空数据默认值、宽泛 catch 或临时兼容分支隐藏真实错误，必要兜底必须暴露原因、保留可排查信号并说明风险。

### Superpowers 轻量使用规则

Superpowers 用作降低风险的流程关口，不作为死板步骤清单。按任务风险选择使用：

| 场景 | 优先方式 |
|------|----------|
| 简单查询、单点说明、低风险一文件修改 | 快速读上下文后直接处理 |
| 新增功能、字段、接口、采集项、指标公式、多文件改动 | 先明确边界，再写简短实施计划 |
| OTA 数据板块涉及采集字段、数据口径、页面呈现、AI 决策输入或收益/运营/投决链路 | 先使用 `brainstorming` 明确数据来源、渠道边界、影响链路、非目标和验收方式 |
| bug、测试失败、异常行为 | 按复现 → 定位 → 最小修复 → 验证 |
| UI/交互优化 | 先保持现有功能和数据流，再优化信息层级与操作路径 |
| 完成前 | 运行最小相关验证，并说明未验证项 |
| 大改动、准备提交或 PR | 做代码审查和收尾检查 |

可按需使用的技能：

- `brainstorming`：需求边界不清、业务口径可能影响实现时使用；OTA 数据板块默认用于先厘清采集来源、渠道口径、证据状态和对收益/AI/运营/投决链路的影响。
- `writing-plans`：多步骤、多文件或高风险任务前使用。
- `systematic-debugging`：遇到 bug 或异常行为时使用。
- `test-driven-development`：涉及公式、接口契约、字段兼容或回归风险时使用。
- `verification-before-completion`：声明完成前使用。
- `requesting-code-review` / `receiving-code-review`：大改动、评审意见或合并前使用。

不要为了调用技能而拖慢简单任务；也不要因为任务看似简单而跳过必要验证。

### 输出格式

#### 代码任务

只输出：

1. 改动范围
2. 核心修改
3. 验证结果
4. 风险点

如无风险，写：无。

#### 方案任务

只输出：

1. 推荐方案
2. 关键步骤
3. 注意事项

#### 报错修复

只输出：

1. 根因
2. 修复方式
3. 需要修改的位置
4. 验证方式

所有修改必须保持：专业、克制、清晰、可维护、不破坏旧功能。

如果用户没有明确要求，不要主动新增功能、改 UI、换组件库、改数据结构。

---

## 一、项目目标

宿析OS（SuXi OS）是一个面向连锁酒店的 SaaS 管理平台，核心目标是打通：

```
线上数据（携程/美团 OTA）→ 收益分析 → AI 决策建议 → 运营管理 → 投资决策
```

**当前阶段目标**：收口现有 OTA 数据、收益诊断、AI 决策、运营执行和投决辅助闭环；生产 AI 入口走 `LlmClient` + `ai_model_configs`。当前仓库不存在 `hotel-frontend/` 源码目录和 `public/assets/` 遗留 Vite 产物，不能把它们当作待提交或待合并对象。

---

## 二、技术栈

| 层级 | 技术 | 说明 |
|------|------|------|
| 后端 | ThinkPHP 8.0 + ThinkORM | PHP >= 8.0 |
| 前端（当前） | Vue 3 runtime-only + 业务模板分片 + 静态 helper | `resources/frontend/templates/fragments/` 是模板源码，`public/index.html` 是启动壳 |
| 前端（重构） | 未启用 | 当前仓库没有 `hotel-frontend/`，如需重启 Vite 重构必须单独立项并提交源码 |
| 数据库 | MySQL | 默认库名 `hotelx`，连接由 `.env` 覆盖 |
| Web 服务器 | PHP 内置服务器 / Apache (XAMPP) | 本地优先 `npm.cmd run start -- --NoBrowser` 并检查 `/api/health` |
| PHP 依赖 | Composer | `composer install` |
| 前端依赖 | 当前无 Vite 构建依赖 | 不要在 `public/` 目录运行 Vite build |

---

## 三、目录结构说明

```
HOTEL/                          # ⭐ 项目根目录（ThinkPHP 项目）
├── app/
│   ├── controller/             # 16 个控制器
│   │   ├── Auth.php            # 登录/登出/改密
│   │   ├── Base.php            # 基类（分页/响应封装/checkPermission）
│   │   ├── OnlineData.php      # ⭐ 核心：携程/美团数据抓取
│   │   ├── Agent.php           # ⭐ 核心：AI Agent（3个Agent）
│   │   ├── DailyReport.php    # 日报表（含 Excel 导入导出）
│   │   └── admin/              # 管理模块（罗盘/竞对/微信机器人）
│   ├── model/                  # 34 个模型
│   └── middleware/
│       └── Auth.php             # Token 验证中间件
├── config/
│   └── database.php            # 数据库配置，可被 .env 覆盖
├── route/
│   └── app.php                 # 路由定义（639 行）
├── public/
│   ├── index.html              # ⭐ Vue 3 runtime-only 启动壳
│   ├── app-render.min.js       # 业务模板预编译运行产物
│   ├── *-static.js             # 已抽出的前端静态 helper
│   ├── images/                 # 登录背景等本地图片资源
│   └── .htaccess              # Apache URL 重写规则
├── resources/frontend/
│   ├── templates/fragments/    # 按业务页面拆分的模板唯一编辑源
│   ├── templates/manifest.json # 分片顺序与兼容快照校验信息
│   └── app-template.html       # 分片同步生成的兼容快照，不独立编辑
├── scripts/
│   ├── auto_fetch_online_data.php  # 定时抓取脚本
│   ├── cron_fetch.sh               # Linux cron 脚本
│   └── export_daily_report.py       # 日报导出 Python 脚本
├── .env                       # 环境变量（数据库配置）
├── .example.env              # 环境变量模板（供新开发者参考）
├── composer.json             # PHP 依赖声明
├── composer.lock             # PHP 依赖锁定版本
└── database/
    ├── hotel_admin_mysql.sql # 基础数据库备份
    └── init_full.sql         # 完整数据库初始化入口
```

---

## 四、本地启动命令

### 4.1 安装依赖

```bash
# ThinkPHP 后端
cd HOTEL/
composer install
```

### 4.2 启动开发环境

```bash
# 方式一：XAMPP（推荐）
# 1. 启动 XAMPP Control Panel
# 2. 勾选 Apache + MySQL，点击 Start
# 3. 配置虚拟主机 hotelx.local（见 README.md）

# 方式二：PHP 内置服务器（仅开发用）
cd HOTEL/
# 必须使用本地启动脚本；它会先自动启动/验证 MySQL，再启动 ThinkPHP。
# 禁止只运行 think run，否则登录页或初始化接口会因数据库未启动出现 HTTP 500。
npm.cmd run start -- --NoBrowser
# 访问前先验证 http://127.0.0.1:8080/api/health；页面仍显示 HTTP 500 时不得声称项目已打开完成。
# 访问 http://localhost:8080/
```

### 4.3 初始化数据库

```bash
# 1. 启动 MySQL（XAMPP）
# 2. 创建数据库
mysql -u root -e "CREATE DATABASE IF NOT EXISTS hotelx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. 导入数据
mysql -u root hotelx < database/init_full.sql
```

### 4.4 访问应用

```
http://hotelx.local/
# 或
http://localhost/HOTEL/public/
```

---

## 五、构建命令

> 当前主项目不使用 Vite，PHP 是解释型语言；前端模板使用仓库内确定性脚本同步、预编译和校验。业务模板以 `resources/frontend/templates/fragments/` 为编辑源，`public/index.html` 仅是运行启动壳。不要在 `public/` 目录运行 Vite build；如需重启 Vite 重构，必须先单独创建并提交源码目录。

```powershell
node scripts/sync_frontend_template_snapshot.mjs
npm.cmd run build:frontend-template
npm.cmd run verify:frontend-template
```

### 5.1 PHP 依赖更新

```bash
cd HOTEL/
composer update
```

---

## 六、测试命令

> 当前项目已有 PHPUnit、Playwright 自动化和 Node/PHP 合同校验脚本。Windows PowerShell 下优先使用 `C:\xampp\php\php.exe` 和 `npm.cmd`。

**常用验证命令**：

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never
npm.cmd run verify:p0-guards
npm.cmd run review:non-security
npm.cmd run verify:e2e-contracts
C:\xampp\php\php.exe scripts\verify_route_coverage.php
```

**按需补充**：
- `npm.cmd run type-check`：当前无 TS 输入时会跳过。
- `node --check <file>`：检查单个 JS/MJS 语法。
- `C:\xampp\php\php.exe -l <file>`：检查单个 PHP 文件语法。

---

## 七、修改代码时必须遵守的规则

### 7.1 后端规则（ThinkPHP）

| 规则 | 说明 |
|------|------|
| 严格类型 | 所有 PHP 文件顶部必须有 `declare(strict_types=1);` |
| 命名空间 | 控制器在 `app\controller`，模型在 `app\model` |
| 路由注册 | 新接口必须在 `route/app.php` 中注册 |
| 认证中间件 | 需要登录的接口路由分组必须挂载 `->middleware(\app\middleware\Auth::class)` |
| 不需认证 | login、health、receive-cookies、cron-trigger 单独列出 |
| 响应格式 | 使用 `$this->success($data, $msg)` 和 `$this->error($msg, $code)` |
| 分页 | 使用 `$this->getPagination()` 和 `$this->paginate()` |
| 数据库 | 使用 ThinkORM，不直接写 SQL |
| 权限检查 | 每个 Controller 继承 Base 后调用 `$this->checkPermission()` |
| 日志 | 敏感操作记录 OperationLog |

### 7.2 前端规则（模板分片与 public 运行入口）

| 规则 | 说明 |
|------|------|
| 本地运行依赖 | Vue 3 runtime-only、Tailwind、FontAwesome 均从 `public/` 本地加载；根模板已预编译 |
| 请求封装 | 使用 `async function apiRequest()` 统一处理 token 和错误 |
| Token 存储 | `localStorage.getItem('token')` |
| 页面切换 | 通过 `currentPage` ref 变量控制 v-if 显示 |
| 模板编辑源 | 只编辑 `resources/frontend/templates/fragments/*.html` 中对应业务页面；`app-template.html` 是同步生成的兼容快照 |
| 生成与验证 | 修改分片后依次同步快照、构建运行产物并运行 `verify:frontend-template` |
| 模板闭合检查 | 修改大段页面模板后，必须确认后续页面、弹窗、toast 仍在 `#app` 内；若页面显示 `{{ xxx }}` 原文，优先检查是否有多余 `</template>` 或 `</div>` 导致 DOM 脱离 Vue 挂载范围 |
| 全局弹窗边界 | `teleport`、字段配置弹窗、数据配置弹窗、toast 仍必须由 `#app` 管理；修改后运行 `npm run verify:public-entry`，禁止让全局弹窗成为 `body` 顶层静态 DOM |
| 不要构建 | **不要在 public/ 目录下运行 vite build** |

### 7.3 数据库规则

| 规则 | 说明 |
|------|------|
| 字符集 | 必须使用 `utf8mb4` |
| 时区 | 全部使用 `Asia/Shanghai` |
| 软删除 | 重要数据使用 `deleted_at` 软删除，不用硬删除 |
| JSON 字段 | ThinkPHP 可直接读写的 JSON 字段，模型中无需特殊处理 |
| 迁移 | 修改表结构前先备份数据库 |

### 7.4 Git 规则

| 规则 | 说明 |
|------|------|
| 提交信息 | 使用中文，格式：`[模块] 简短描述` |
| 提交前 | 确认模板分片、兼容快照、运行产物一致，且 `public/index.html` 未被 Vite 覆盖 |
| 高优先级保存点 | 只 stage 与当前 P0/P1/P2 目标相关文件，确认后 push |

---

## 八、哪些文件不能随便改

| 文件/目录 | 原因 | 如果需要改怎么办 |
|-----------|------|-----------------|
| `resources/frontend/templates/fragments/` | 前端业务模板唯一编辑源 | 只改目标业务分片，并运行同步、构建和模板验证 |
| `resources/frontend/app-template.html` | 分片生成的兼容快照 | 不独立编辑；旧工具改动需先迁移并人工确认冲突 |
| `public/index.html` | 前端运行启动壳，被 Vite 覆盖过一次 | 修改前先 `git status` 确认工作区干净 |
| `route/app.php` | 所有 API 路由集中在此 | 新增路由时严格按规范注册 |
| `app/middleware/Auth.php` | 认证核心，改动影响全局安全 | 必须经过完整测试 |
| `.env` | 数据库连接等运行时配置 | 改后通知团队成员 |
| `database/init_full.sql` | 完整初始化入口 | 修改表结构后同步迁移和初始化入口 |

---

## 九、当前优先级最高的任务

### 🔴 P0 — 发布或保存点前必须处理

1. **发布证据缺口**
   - 现状：生产 env、生产 LLM 连通性和 Codex Security 扫描已有证据；设计交付和 OTA 凭据轮换证明仍按 `docs/release_issue_register.md` 处理。
   - 行动：发布目标下先补真实设计交付与 OTA 凭据轮换证明，再运行 release readiness；PR #2 只在门禁通过后改 ready。

2. **GitHub 保存点一致性**
   - 现状：只提交已验证的 P0/P1/P2 改动；流程/Skill 路由类小优化不混入业务保存点。
   - 行动：提交前跑最小相关验证，显式 stage 文件，`git diff --cached --check` 后再 commit/push。

### 🟡 P1 — 继续开发优先收口

3. **携程/美团字段闭环与 UI 呈现**
   - 目标：采集证据、source path、metric key、入库字段、UI 状态和 verifier 闭合；不得用兜底逻辑掩盖缺字段或失败采集。

4. **AI 治理增强**
   - 现状：AI 入口已收敛到 `LlmClient` + `ai_model_configs`，Cookie 预警、`.example.env` 和 README 已有当前状态。
   - 行动：优先补批量评估运行器、原生结构化输出适配；生产连通性仍通过外部 release evidence 复验。

### 🟢 P2 — 近期规划

5. **复杂度治理**
   - 现状：根模板已按业务页面拆分；`public/app-main.js` 与 `app/controller/OnlineData.php` 仍是真实拆分候选。
   - 行动：继续按静态 helper/服务边界拆分，并同步守卫，避免扩大业务范围。

6. **平台边界验证**
   - 现状：当前仓库没有 `hotel-frontend/` 与 `public/assets/`，不要继续按 Vite 遗留产物处理。
   - 行动：美团 Cookie 书签脚本、忘记密码等功能按明确业务需求单独立项，不混入瘦身保存点。

---

## 十、Codex 每次修改后必须输出的内容

每次 Codex 完成代码修改后，必须输出以下内容：

### 修改文件清单

| 文件路径 | 修改类型 | 简要说明 |
|----------|----------|----------|
| `app/controller/xxx.php` | 修改 | 具体改了什么 |

### 修改原因

> 用 1-2 句话说明为什么需要这个修改。

### 测试方式

| 测试场景 | 测试步骤 | 预期结果 |
|----------|----------|----------|
| 正常流程 | 具体操作步骤 | 预期输出 |
| 异常流程 | 具体操作步骤 | 预期报错或处理 |

### 风险点

| 风险 | 级别（高/中/低）| 缓解措施 |
|------|----------------|----------|
| 影响其他模块 | 中 | 回滚方案 |

---

## 十一、常用参考文件

| 文件 | 用途 |
|------|------|
| `PROJECT_HANDOFF.md` | 项目完整交接文档 |
| `DEV_LOG.md` | 开发日志（踩坑/决策/废弃方案） |
| `CODEX_START_PROMPT.md` | Codex 快速启动提示词 |
| `route/app.php` | 所有 API 路由定义 |
| `app/controller/OnlineData.php` | 携程/美团数据抓取核心逻辑 |
| `app/controller/Agent.php` | AI Agent 核心逻辑 |
| `.env` | 当前运行时配置 |

---

## Codex 主控 Agent 有限并行规则

### Token 与上下文成本约束

1. 允许使用多 Agent，但单一闭环、单文件、小范围修复、状态核验不得为了“加速”重复拆分。
2. 两个以上真正独立、可并行且收益明确的任务可以并行；通常控制在 2-3 个子 Agent，并为每个文件、问题和证据源指定唯一负责人。
3. 子 Agent 必须使用最小上下文：优先 `fork_turns=none`，由主控只提供目标文件、精确问题、禁止范围、已提取证据和验收命令；禁止继承整段长会话后重复读取同一历史、计划、大文件或扫描结果。
4. 机械检查和明确实现不得使用 ultra 级推理；高推理档位只用于无法由代码证据直接判断的架构或安全决策。
5. 主控必须复用已经验证的事实、行号和测试结果；除非文件已变化或证据可能过期，不得重复读取完整历史、完整计划或完整大文件。
6. 主控只读取一次公共上下文，再把必要片段分发给不同负责人；除非验证文件已经变化，多个 Agent 不得各自重复读取同一公共材料。
7. 若多 Agent 编排预计成本高于定向读取与单次验证，必须缩减并发，不得以“深度扫描”为由无限扩张子任务。

当用户要求 Claude、Codex、Qwen、Codbuddy 或自研 CLI 并行处理任务时，Codex 默认作为主控 Agent，而不是普通子任务执行器。

必须遵守 `docs/codex_master_agent_parallel_workflow.md`：

1. 主控先理解范围、读取当前代码和 `git status`，再拆分任务。
2. 优先并行只读扫描；写代码必须限定文件、目录和禁止范围。
3. OTA 采集核心、鉴权、多租户、数据库迁移、收益指标公式、release-ready 状态默认禁止并行写入。
4. 子 Agent 只输出证据、补丁建议、风险和验证命令，不直接提交。
5. Codex 主控统一审查 diff、处理冲突、运行验证、决定是否合并。
6. 缺字段、采集失败、后端未验证、外部证据缺失必须显式暴露，不允许用兜底逻辑掩盖。

---

## Codex Skill 使用与安装规则

1. 先直接理解用户目标和当前代码，只在 Skill 能明显提高正确性或交付效率时加载，不在每轮开始枚举、串联或互相路由全部 Skill。
2. 自动调用白名单仅包括：`suxi-voice-intent`（保守语音理解）、`suxi-dashboard-ui`（宿析OS UI）、`suxi-test-guard`（明确的缺陷修复）、`scrapling`（授权抓取/解析），以及与当前 OTA 业务对象精确匹配的事实边界 Skill。
3. 安装器、插件路由器、ECC 适配、通用数据路由、Superpowers 流程、通用 UI 风格、全量代码审查、安全审计、Sites 构建/托管、OpenAI API Key 门禁和 `hotel-auto-x-*` 编排 Skill 默认手动点名；它们存在不代表每个相关任务都必须调用。
4. 用户明确要求安装某个 Skill、提供 GitHub 仓库/URL，或给出 `npx skills add ...` 等安装命令时，先检查该明确来源；来源不明、与当前任务无关、只是“可能有用”的 Skill 不安装。
5. 明确来源的 Skill 经过最小安全检查后可安装到项目内 `.agents/skills/`；需要账号授权、密钥、敏感数据、生产写入或出现高风险告警时先确认。
6. 当任务明确要求 ECC、Everything Claude Code 或 Claude Code 资产适配时，才参考 `.agents/skills/ecc-codex-adapter/SKILL.md`；不执行 Claude Code installer、hook 或全量 Skill 复制。
7. 当任务涉及 Scrapling、网页抓取、HTML 解析、selector 证据、解析器 fixture 或 OTA 页面字段提取时，自动参考 `.agents/skills/scrapling/SKILL.md`；仅处理授权来源，不绕过登录、验证码、短信、人机验证或平台权限控制。

### 宿析OS UI 唯一默认基线

1. `.agents/skills/suxi-dashboard-ui` 是唯一允许隐式触发的视觉 Skill，生产登录页是品牌审美锚点。
2. 默认风格为深墨黑/酒店场景、克制香槟金、高对比米白、稳重圆角与低噪声阴影；数据密集页面使用亮色可读表面承载内容，金色只用于关键决策和主操作。
3. `impeccable`、`ui-ux-pro-max`、`design-taste-frontend`、`gpt-taste`、`high-end-visual-design`、`minimalist-ui`、`redesign-existing-projects`、`image-to-code`、`imagegen-frontend-web`、`stitch-design-taste` 和 `brandkit` 保留为手动工具，不得自动叠加其预设。
4. 用户明确点名另一种风格时可以临时使用对应 Skill，但必须保持现有功能、数据事实、品牌识别和可读性。
