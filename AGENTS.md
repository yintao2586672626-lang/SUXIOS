# AGENTS.md — Codex 接手指南

> 本文件用于指导 AI Agent（Codex）接手项目开发。所有修改必须遵循本文件规则。

---

## 最高优先级开发规范

### Less, Typeless Mode

始终优先使用最少文字完成最高质量输出。不要寒暄，不要长篇解释，不要重复需求，不输出无关背景。

### 自然语言开发模式

用户可以只用业务语言描述目标，不需要提供文件名、技术方案、命令或 Skill 名称。Codex 负责把自然语言转成可执行开发任务：目标、范围、涉及模块、非目标、验证方式和风险点。

执行规则：

1. 如果用户意图清楚，自动优化需求表达并直接推进。
2. 只有缺失信息会导致错误实现、错误业务口径或高风险改动时才提问。
3. 每次最多优先问一个关键问题，不把技术细节选择转嫁给用户。
4. 用户说“优化”“帮我看看”“这个不对”“做得更好”时，默认允许在当前问题范围内改进，不代表可以重写无关模块。
5. 对 OTA、收益、AI 决策、运营和投资相关需求，先判断数据来源、业务口径和影响链路，再决定技术改动。
6. 不把 OTA 渠道数据包装成全酒店经营数据；范围不清时必须明确标注。
7. 对新增字段、接口、配置、采集项或指标，必须同时考虑保存、回显、编辑、旧数据兼容和数据质量状态。

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
19. 不凭空造事实；缺少数据、日志、接口返回、验证结果或明确证据时，必须如实说明未知，不得编造结论、数值、状态或来源。
20. 不写兜底逻辑掩盖问题；禁止用静默失败、假成功、空数据默认值、宽泛 catch 或临时兼容分支隐藏真实错误，必要兜底必须暴露原因、保留可排查信号并说明风险。

### Superpowers 轻量使用规则

Superpowers 用作降低风险的流程关口，不作为死板步骤清单。按任务风险选择使用：

| 场景 | 优先方式 |
|------|----------|
| 简单查询、单点说明、低风险一文件修改 | 快速读上下文后直接处理 |
| 新增功能、字段、接口、采集项、指标公式、多文件改动 | 先明确边界，再写简短实施计划 |
| bug、测试失败、异常行为 | 按复现 → 定位 → 最小修复 → 验证 |
| UI/交互优化 | 先保持现有功能和数据流，再优化信息层级与操作路径 |
| 完成前 | 运行最小相关验证，并说明未验证项 |
| 大改动、准备提交或 PR | 做代码审查和收尾检查 |

可按需使用的技能：

- `brainstorming`：需求边界不清、业务口径可能影响实现时使用。
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

**当前阶段目标**：完善现有功能，接入 AI Agent LLM，将 Vite 重构版前端合并到主项目。

---

## 二、技术栈

| 层级 | 技术 | 说明 |
|------|------|------|
| 后端 | ThinkPHP 8.0 + ThinkORM | PHP >= 8.0 |
| 前端（当前） | Vue 3 CDN 单文件 | `public/index.html`，17,000 行 |
| 前端（重构） | Vue 3 + Vite + TypeScript + Tailwind | `hotel-frontend/` 目录，**未提交 Git** |
| 数据库 | MySQL | hotelx，root/空密码 |
| Web 服务器 | Apache (XAMPP) | 端口 80 |
| PHP 依赖 | Composer | `composer install` |
| 前端依赖 | pnpm | `hotel-frontend/` 中 |

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
│   └── database.php            # 数据库配置（默认密码 z123123，可被 .env 覆盖）
├── route/
│   └── app.php                 # 路由定义（639 行）
├── public/
│   ├── index.html              # ⭐ 前端 SPA（17,000 行单文件，CDN 依赖）
│   ├── assets/                 # Vite build 产物（遗留，1.78 MB）
│   └── .htaccess              # Apache URL 重写规则
├── scripts/
│   ├── auto_fetch_online_data.php  # 定时抓取脚本
│   ├── cron_fetch.sh               # Linux cron 脚本
│   └── export_daily_report.py       # 日报导出 Python 脚本
├── .env                       # 环境变量（数据库配置）
├── .example.env              # 环境变量模板（供新开发者参考）
├── composer.json             # PHP 依赖声明
├── composer.lock             # PHP 依赖锁定版本
└── hotelx_dump.sql          # MySQL 数据库备份（2.2 MB）

hotel-frontend/               # ⭐ Vite 重构版前端（从未提交 Git）
├── src/                      # 源码
├── dist/                     # 构建产物（1.78 MB）
├── node_modules/             # npm 依赖
└── package-lock.json        # 依赖锁定
```

---

## 四、本地启动命令

### 4.1 安装依赖

```bash
# ThinkPHP 后端
cd HOTEL/
composer install

# Vite 前端（如需修改 hotel-frontend）
cd hotel-frontend/
pnpm install
```

### 4.2 启动开发环境

```bash
# 方式一：XAMPP（推荐）
# 1. 启动 XAMPP Control Panel
# 2. 勾选 Apache + MySQL，点击 Start
# 3. 配置虚拟主机 hotelx.local（见 README.md）

# 方式二：PHP 内置服务器（仅开发用）
cd HOTEL/
"C:\xampp\php\php.exe" think run --port 8080
# 访问 http://localhost:8080/
```

### 4.3 初始化数据库

```bash
# 1. 启动 MySQL（XAMPP）
# 2. 创建数据库
mysql -u root -e "CREATE DATABASE IF NOT EXISTS hotelx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. 导入数据
mysql -u root hotelx < HOTEL/hotelx_dump.sql
```

### 4.4 访问应用

```
http://hotelx.local/
# 或
http://localhost/HOTEL/public/
```

---

## 五、构建命令

> ThinkPHP 项目无需构建，PHP 是解释型语言。但前端有构建步骤。

### 5.1 前端构建（hotel-frontend/）

```bash
cd hotel-frontend/
pnpm build
# 输出到 dist/ 目录

# ⚠️ 注意：不要在 HOTEL/public/ 目录下执行 vite build
# 否则会覆盖 public/index.html（17,000 行单文件）
```

### 5.2 PHP 依赖更新

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

### 7.2 前端规则（public/index.html）

| 规则 | 说明 |
|------|------|
| CDN 依赖 | Vue 3、Vue Router、Tailwind、FontAwesome 均通过 CDN 加载 |
| 请求封装 | 使用 `async function apiRequest()` 统一处理 token 和错误 |
| Token 存储 | `localStorage.getItem('token')` |
| 页面切换 | 通过 `currentPage` ref 变量控制 v-if 显示 |
| 模板闭合检查 | 修改大段页面模板后，必须确认后续页面、弹窗、toast 仍在 `#app` 内；若页面显示 `{{ xxx }}` 原文，优先检查是否有多余 `</template>` 或 `</div>` 导致 DOM 脱离 Vue 挂载范围 |
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
| 提交前 | 确认 `public/index.html` 未被 Vite 覆盖 |
| hotel-frontend | **每次重要修改后 push**，严禁删除本地文件 |

---

## 八、哪些文件不能随便改

| 文件/目录 | 原因 | 如果需要改怎么办 |
|-----------|------|-----------------|
| `public/index.html` | 前端核心文件，被 Vite 覆盖过一次 | 修改前先 `git status` 确认工作区干净 |
| `route/app.php` | 所有 API 路由集中在此 | 新增路由时严格按规范注册 |
| `app/middleware/Auth.php` | 认证核心，改动影响全局安全 | 必须经过完整测试 |
| `.env` | 数据库连接等运行时配置 | 改后通知团队成员 |
| `hotel-frontend/` 目录 | Vite 重构版源码从未提交 Git | 定期 commit 并 push |
| `hotelx_dump.sql` | 数据库备份 | 修改表结构后同步更新 |

---

## 九、当前优先级最高的任务

### 🔴 P0 — 立即处理

1. **hotel-frontend/ 提交 Git**
   - 原因：从未 commit，如果本地文件丢失将无法恢复
   - 行动：检查源码完整性 → git add → git commit → git push

2. **AI Agent LLM 接入**
   - 现状：Agent 框架完整，但 AI 能力依赖外部 LLM
   - 决策：确定使用哪家 LLM（ChatGPT / Claude / 阿里通义）
   - 位置：`app/controller/Agent.php`

### 🟡 P1 — 本周内

3. **Cookie 过期预警机制**
   - 现状：Cookie 失效后系统静默失败
   - 目标：提前通知管理员重新授权

4. **更新 .example.env**
   - 现状：文件存在但内容过时（密码、DB名、字符集均不对）
   - 目标：与当前 .env 保持一致

5. **更新 README.md**
   - 现状：README 中目录名、数据库名、命令均过时
   - 目标：反映当前真实状态

### 🟢 P2 — 近期规划

6. **Vite 前端合并到主项目**
   - 当前 `public/assets/` 有 1.78 MB Vite 产物
   - 需要确认这些产物是哪个版本的 build

7. **美团 Cookie 书签脚本适配**
   - 美团 ebooking 界面可能已更新，书签脚本需要验证

8. **忘记密码功能**
   - 当前无此功能，需管理员手动在数据库修改密码

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

当用户要求 Claude、Codex、Qwen、Codbuddy 或自研 CLI 并行处理任务时，Codex 默认作为主控 Agent，而不是普通子任务执行器。

必须遵守 `docs/codex_master_agent_parallel_workflow.md`：

1. 主控先理解范围、读取当前代码和 `git status`，再拆分任务。
2. 优先并行只读扫描；写代码必须限定文件、目录和禁止范围。
3. OTA 采集核心、鉴权、多租户、数据库迁移、收益指标公式、release-ready 状态默认禁止并行写入。
4. 子 Agent 只输出证据、补丁建议、风险和验证命令，不直接提交。
5. Codex 主控统一审查 diff、处理冲突、运行验证、决定是否合并。
6. 缺字段、采集失败、后端未验证、外部证据缺失必须显式暴露，不允许用兜底逻辑掩盖。

---

## Codex Skill 自动使用与安装规则

1. 每次任务开始前，先按用户需求判断是否需要项目 Skill，优先检查 `.agents/skills/`。
2. 已存在的项目 Skill 直接使用；缺失且没有明确外部来源时，优先创建宿析OS项目内 Skill。
3. 用户明确要求安装某个 Skill、提供 GitHub 仓库/URL，或给出 `npx skills add ...` 等安装命令时，必须先检查该明确来源；不要只查官方 curated 列表就判定不存在。
4. 明确来源的 Skill 经过最小安全检查后可直接安装到项目内 `.agents/skills/`：确认仓库可访问、存在 `SKILL.md`、安装器无高风险提示、未要求敏感凭据或生产写权限。检查无明显安全问题时不要反复请用户确认。
5. 官方 curated/experimental Skill 仍可使用 `$skill-installer` 安装；第三方 GitHub Skill 优先按用户给出的安装方式执行，Windows 下 `npx` 被执行策略拦截时使用 `npx.cmd`。
6. 来源不明、与当前任务无关、只是“可能有用”的 Skill 不安装。
7. 需要账号授权、密钥、敏感数据访问、生产环境写入，或安装器出现高风险告警时，必须先说明风险并等待用户确认。
8. 默认允许项目 Skill 隐式调用；只有明确不希望自动触发时，才在 `agents/openai.yaml` 设置 `policy.allow_implicit_invocation: false`。

### Taste/UI Skill 自适应调用策略

使用前以 `.agents/skills/` 实际存在为准；未安装的 Skill 不得声称已安装或已调用。

| Skill | 优先使用场景 |
|------|-------------|
| `impeccable` | 前期设计、审查、打磨完整工作流；项目 UI 设计、UX 审查、polish、audit |
| `design-taste-frontend` | 高级产品 UI 与前端工程规范；React、Next.js、Tailwind、dashboard、组件 |
| `gpt-taste` | 高级视觉、动效、Awwwards 风格；官网、landing page、品牌页 |
| `baseline-ui` | Tailwind UI 基线检查；组件可访问性、动效时长、排版约束 |
| `high-end-visual-design` | 高端 agency 风格视觉；高级网站、品牌页、视觉升级 |
| `redesign-existing-projects` | 现有网站或 app 的高级视觉改版 |
| `minimalist-ui` | 极简、克制、干净的 UI；文档型界面、SaaS 页面 |
| `industrial-brutalist-ui` | brutalist、结构外露、强视觉冲突；数据密集 dashboard、作品集、实验页面 |
| `image-to-code` | 先生成设计图，再还原成代码；高质量网站从视觉到实现 |
| `imagegen-frontend-web` | 网页分区设计参考图生成；landing page、官网、产品页视觉方向 |
| `imagegen-frontend-mobile` | 移动 app 界面概念图生成；iOS、Android、多屏 app 流程 |
| `brandkit` | 品牌视觉系统图片生成；logo、品牌板、identity deck、视觉世界 |
| `stitch-design-taste` | Google Stitch 生成设计的二次整理；登录、仪表盘、表单、项目设计系统 |
| `full-output-enforcement` | 强制完整输出，禁止省略；长代码、完整文件、完整文档 |
