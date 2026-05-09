# AGENTS.md — Codex 接手指南

> 本文件用于指导 AI Agent（Codex）接手项目开发。所有修改必须遵循本文件规则。

---

## 最高优先级开发规范

### Less, Typeless Mode

始终优先使用最少文字完成最高质量输出。不要寒暄，不要长篇解释，不要重复需求，不输出无关背景。

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
线上数据（携程/美团 OTA）→ 收益分析 → AI 决策建议 → 运维管理
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

> ⚠️ **当前项目没有测试框架。**

- 无 PHPUnit（`phpunit.xml` 不存在）
- 无功能测试
- 无单元测试

**如果需要添加测试**：

```bash
# 安装 PHPUnit
composer require --dev phpunit/phpunit

# 生成 phpunit.xml 配置
php vendor/bin/phpunit --generate-configuration
```

**当前测试方式**：
- 手动测试 API：`curl` 或 Postman
- 测试脚本：`test-api.ps1`、`test-login.ps1`
- 健康检查：`GET /api/health`

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

## Codex Skill 自动使用与安装规则

1. 每次任务开始前，先按用户需求判断是否需要项目 Skill，优先检查 `.agents/skills/`。
2. 已存在的项目 Skill 直接使用；缺失时优先创建宿析OS项目内 Skill。
3. 只有明确属于官方 curated skill 时，才允许使用 `$skill-installer` 安装。
4. 来源不明、与当前任务无关、只是“可能有用”的 Skill 不安装。
5. 需要联网、外部权限或账号授权的 Skill，必须先说明风险并等待用户确认。
6. 默认允许项目 Skill 隐式调用；只有明确不希望自动触发时，才在 `agents/openai.yaml` 设置 `policy.allow_implicit_invocation: false`。
