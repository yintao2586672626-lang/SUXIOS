# PACKAGE_MANIFEST.md — 打包清单

> 说明最终打包包含哪些文件、排除了哪些文件，以及排除原因。

---

## 一、打包基本信息

- **推荐压缩包名称**：`SuXiOS-Codex-Handoff.zip`
- **项目路径**：`d:\桌面\国际\JD-main\HOTEL\`
- **打包不含的内容**：`.env`（含本地数据库配置）、`vendor/`（需 `composer install`）、所有调试脚本、所有 Vite build 产物

---

## 二、打包包含的文件清单

### 2.1 核心文档（必须包含）

| 文件 | 大小（估算） | 说明 |
|------|-------------|------|
| `README.md` | ~8 KB | 项目说明、安装步骤、常见问题 |
| `AGENTS.md` | ~12 KB | Codex 开发规范与任务清单 |
| `PROJECT_HANDOFF.md` | ~17 KB | 完整项目交接文档 |
| `CODEX_START_PROMPT.md` | ~5 KB | Codex 快速启动提示词 |
| `DEV_LOG.md` | ~9 KB | 开发日志（踩坑/决策/废弃方案） |
| `PACKAGE_MANIFEST.md` | 本文件 | 打包清单 |

### 2.2 核心源码

| 文件/目录 | 说明 |
|-----------|------|
| `app/controller/` | 16 个业务控制器（含 Auth、OnlineData、Agent 等核心） |
| `app/model/` | 34 个 Eloquent 模型 |
| `app/middleware/Auth.php` | Token 认证中间件 |
| `config/` | ThinkPHP 配置（含 database.php） |
| `route/app.php` | 路由定义（639 行） |
| `extend/` | ThinkPHP 扩展目录 |
| `view/` | ThinkPHP 模板目录 |
| `scripts/` | 定时脚本（auto_fetch_online_data.php、cron_fetch.sh、export_daily_report.py） |

### 2.3 前端资源

| 文件/目录 | 说明 |
|-----------|------|
| `public/index.html` | **核心**：前端 SPA（17,000 行单文件 Vue 3） |
| `public/.htaccess` | Apache URL 重写规则 |
| `public/vue.global.prod.js` | Vue 3 CDN 备份 |
| `public/vue-router.global.prod.js` | Vue Router CDN 备份 |
| `public/tailwind.min.css` | Tailwind CSS |
| `public/font-awesome.min.css` | FontAwesome |
| `public/style.css` | 自定义样式 |
| `public/favicon.ico` | 网站图标 |
| `public/images/` | 图片资源 |
| `public/webfonts/` | 字体文件 |
| `public/robots.txt` | 搜索引擎爬虫规则 |

### 2.4 配置文件

| 文件 | 说明 |
|------|------|
| `.example.env` | 环境变量模板（不含真实密钥） |
| `.gitignore` | Git 忽略规则 |
| `composer.json` | PHP 依赖声明 |
| `composer.lock` | PHP 依赖锁定版本 |
| `think` | ThinkPHP CLI 入口脚本 |

### 2.5 数据库

| 文件 | 说明 |
|------|------|
| `hotelx_dump.sql` | MySQL 数据库备份（2.2 MB，含完整 schema 和数据） |

---

## 三、打包排除的文件清单

### 3.1 依赖目录（必须排除）

| 排除路径 | 原因 | 如何恢复 |
|----------|------|----------|
| `vendor/` | Composer 依赖（约 2.7 MB），可通过 `composer install` 恢复 | `composer install` |
| `node_modules/`（根目录） | 根目录可能有的 npm 依赖残留 | `pnpm install`（hotel-frontend） |
| `hotel-frontend/node_modules/` | hotel-frontend 的 npm 依赖 | `cd hotel-frontend && pnpm install` |
| `hotel-frontend/dist/` | Vite 构建产物（1.78 MB） | `cd hotel-frontend && pnpm build` |

### 3.2 运行时缓存（必须排除）

| 排除路径 | 原因 | 如何恢复 |
|----------|------|----------|
| `runtime/cache/` | ThinkPHP 缓存文件 | 自动重建 |
| `runtime/temp/` | ThinkPHP 临时文件 | 自动重建 |
| `runtime/log/` | 日志文件 | 自动重建 |

### 3.3 调试脚本（排除）

| 文件 | 大小 | 排除原因 |
|------|------|----------|
| `public/diag2.php` | ~5 KB | 调试脚本，泄露数据库信息 |
| `public/diag4.php` | ~5 KB | 调试脚本 |
| `public/diag_all_keys.php` | ~5 KB | 调试脚本 |
| `public/list_users.php` | ~5 KB | 调试脚本，泄露用户信息 |
| `public/fix_2705.py` | ~5 KB | 修复脚本，一次性使用 |
| `public/generate_qrcode.php` | ~5 KB | 一次性工具 |
| `public/generate_tailwind.py` | ~5 KB | 一次性工具 |
| `public/clean_inline_js.py` | ~5 KB | 一次性清理脚本 |
| `public/extract_js.py` | ~5 KB | 一次性提取脚本 |
| `public/nginx.htaccess` | ~2 KB | Nginx 配置，不适用 Apache |

### 3.4 诊断报告（排除）

| 文件 | 排除原因 |
|------|----------|
| `public/performance-report.html` | 性能报告，一次性 |
| `public/PERFORMANCE_OPTIMIZATION_REPORT.md` | 性能优化报告，一次性 |
| `public/ui-design-system.md` | UI 设计文档，一次性 |
| `public/ui-example.html` | UI 示例，一次性 |

### 3.5 开发辅助文件（排除）

| 文件 | 排除原因 |
|------|----------|
| `public/tailwind.min.css.bak` | Tailwind CSS 备份文件，已有过渡版本 |
| `public/app-main.d.ts` | TypeScript 类型定义，Vite 过渡期残留 |
| `public/app-main.js` | Vite 过渡期 JS，Vite 构建后已废弃 |
| `public/app-styles.css` | Vite 过渡期样式，废弃 |
| `public/app.js` | Vite 过渡期 JS，废弃 |

### 3.6 根目录垃圾文件（排除）

> 以下文件位于 `d:\桌面\国际\JD-main\` 根目录，不属于 HOTEL 项目本身：

| 文件 | 排除原因 |
|------|----------|
| `debug.log` | 调试日志文件 |
| `test-api.ps1` | 测试脚本，本地临时 |
| `test-login.ps1` | 测试脚本，本地临时 |
| `check_html.py` | 一次性工具脚本 |
| `commit.bat` | 本地 Git 提交脚本 |
| `push.bat` | 本地 Git 推送脚本 |
| `delete_useless.ps1` | 一次性清理脚本 |
| `fix_*.php` 系列（6个） | 一次性修复脚本 |
| `new_hash.txt` | 临时哈希文件 |
| `test_password.php` | 一次性测试脚本 |
| `tsconfig.json` | TypeScript 配置（属于 hotel-frontend，不属于 HOTEL） |
| `tsconfig.build.json` | 同上 |
| `TYPESCRIPT_MIGRATION.md` | 迁移文档，一次性 |
| `JD-Hotel-Project.zip` | 旧版本压缩包，已过时 |
| `hotelx_dump.sql.gz` | 压缩版数据库备份（已有未压缩版） |
| `hotel_backup_*.sql` 系列（6个） | 历史备份版本，重复 |

### 3.7 其他排除项

| 排除路径/文件 | 原因 |
|---------------|------|
| `.DS_Store` | macOS 系统文件 |
| `Thumbs.db` | Windows 缩略图缓存 |
| `*.log` | 日志文件 |
| `.env` | **运行时配置**，含本地数据库连接信息，**禁止打包** |
| 任何含真实密钥的文件 | 安全风险 |

---

## 四、最终打包命令

```bash
# 1. 进入项目目录
cd "d:\桌面\国际\JD-main\HOTEL"

# 2. 安装 PHP 依赖（排除 vendor 后，新开发者需要执行）
composer install

# 3. 打包（排除 vendor、runtime、调试脚本等）
# Windows PowerShell：
7z a -xr!vendor -xr!runtime -xr!*.log -xr!diag*.php -xr!fix_*.php -xr!test*.php ^
    -xr!check_*.py -xr!clean_*.py -xr!extract_*.py -xr!generate_*.py ^
    -xr!generate_*.php -xr!list_*.php -xr!fix_*.py ^
    -xr!performance*.html -xr!PERFORMANCE*.md -xr!ui-design*.md -xr!ui-example.html ^
    -xr!tailwind.min.css.bak -xr!app-main.* -xr!app-styles.css -xr!app.js ^
    -xr!nginx.htaccess ^
    -xr!.DS_Store -xr!Thumbs.db ^
    "d:\桌面\国际\SuXiOS-Codex-Handoff.zip" ./

# 或使用以下排除文件列表的 .gitignore 风格配置
```

---

## 五、推荐 .gitignore（如果需要）

如果要将 HOTEL 项目纳入 Git 管理，建议添加以下 `.gitignore` 规则：

```gitignore
# PHP 依赖（通过 composer install 恢复）
vendor/

# ThinkPHP 运行时
runtime/

# 本地环境配置
.env
.env.local
.env.production

# 调试脚本
public/diag*.php
public/fix_*.php
public/list_*.php
public/test*.php
public/check_*.py
public/clean_*.py
public/extract_*.py
public/generate_*.py
public/generate_*.php

# 诊断报告
public/performance*.html
public/PERFORMANCE*.md
public/ui-design*.md
public/ui-example.html

# 废弃的 Vite 过渡文件
public/tailwind.min.css.bak
public/app-main.*
public/app-styles.css
public/app.js
public/nginx.htaccess

# 系统文件
.DS_Store
Thumbs.db

# 日志
*.log
debug.log

# Vite 构建产物（hotel-frontend 项目单独管理）
# hotel-frontend/node_modules/
# hotel-frontend/dist/
```

---

## 六、打包前后对比

| 项目 | 打包前 | 打包后（估算） |
|------|--------|---------------|
| 总大小 | ~50 MB（含 vendor + node_modules） | ~15 MB（源码 + 文档 + 数据库备份） |
| 核心源码 | 包含 | 包含 |
| vendor/ | 2.7 MB（已安装） | ❌ 排除（需 composer install） |
| hotel-frontend/node_modules | ❌ 不在 HOTEL 内 | N/A |
| 调试脚本 | ~30 个文件 | ❌ 全部排除 |
| 数据库备份 | hotelx_dump.sql | hotelx_dump.sql |
| 文档 | 0 个 | 6 个（README/AGENTS/PROJECT_HANDOFF/CODEX_START/DEV_LOG/PACKAGE_MANIFEST） |
