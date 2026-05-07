# 开发日志（DEV_LOG）

> 记录开发过程中的关键决策、踩坑、报错、解决方案、废弃方案、注意事项。

---

## 一、重大决策记录

### 2026-02-26 — 项目命名与定位确定

- **决策**：将系统命名为「宿析OS」（SuXi OS），定位为面向连锁酒店的 SaaS 管理平台。
- **核心功能链路**：线上数据（携程/美团）→ 收益分析 → AI 决策 → 运维管理。
- **相关文件**：全部源代码

### 2026-02-26 — 前端技术选型（CDN 单文件版）

- **决策**：前端采用 Vue 3 + Vue Router + Tailwind CSS + FontAwesome 全部通过 CDN 加载，写入单个 `public/index.html`。
- **原因**：快速开发、零构建依赖、部署简单（只需 PHP 服务器）。
- **代价**：17,000 行代码全在一个文件，状态量 ~66 个 ref，命名冲突风险高，维护困难。
- **后续**：启动 `hotel-frontend/` Vite 重构项目。

### 2026-03 — Vite 重构版立项

- **决策**：将前端重构为 `hotel-frontend/` Vue 3 + Vite + TypeScript + Tailwind CSS 项目。
- **计划文件**：CodeBuddy Plan → `plan.md`
- **迁移策略**：21 个页面按复杂度分阶段迁移（简单→中等→复杂→AI）
- **⚠️ 状态**：hotel-frontend/ 源码从未提交 Git，仅本地存在。**这是当前最大的代码安全风险。**

### 2026-03-18 — Git 恢复 index.html

- **决策**：从 `origin/main` 恢复被 Vite 覆盖的 `public/index.html`（从 552 字节恢复到 17,137 行 / 1.28 MB）。
- **方法**：`git checkout origin/main -- HOTEL/public/index.html`
- **教训**：hotel-frontend/ 应尽快提交 Git

### 2026-03-18 — 数据库组织整理

- **决策**：数据库存储在 XAMPP MySQL 中，`hotelx_dump.sql` 作为备份文件复制到 HOTEL 目录。
- **数据库名**：hotelx，用户 root，空密码

---

## 二、踩坑记录

### 坑 1：Cookie 失效导致抓取失败 ⭐ 高频

| 项目 | 内容 |
|------|------|
| 现象 | API 返回 403 / 401 错误，或携程/美团返回"登录失效" |
| 原因 | 携程/美团 Cookie 有效期通常只有 1-7 天 |
| 解决 | 定期重新运行书签脚本重新获取 Cookie |
| 预防 | 需要实现 Cookie 过期预警机制 |
| 位置 | `OnlineData.php` — `receiveCookies()`、`fetchCtrip()`、`fetchMeituan()` |

### 坑 2：美团 API 日期格式与携程不一致 ⭐ 高频

| 项目 | 内容 |
|------|------|
| 现象 | 美团 API 请求成功但返回空数据 |
| 原因 | 美团要求日期格式 `YYYYMMDD`，携程要求 `YYYY-MM-DD` |
| 解决 | `OnlineData.php` 中 `fetchMeituan()` 对日期做了转换 |
| 代码 | `$params['startDate'] = str_replace('-', '', $startDate);` |

### 坑 3：Vite 打包覆盖 index.html ⭐ 重大

| 项目 | 内容 |
|------|------|
| 现象 | 执行 `vite build` 后，`public/index.html` 从 1.28 MB 变成 552 字节 |
| 原因 | Vite 默认将 `public/index.html` 作为入口，打包时用自己生成的 HTML 替换 |
| 恢复 | `git checkout origin/main -- HOTEL/public/index.html` |
| 预防 | 前端源码在 `hotel-frontend/`，不要在 ThinkPHP 的 `public/` 下进行前端构建 |

### 坑 4：数据库密码不一致

| 项目 | 内容 |
|------|------|
| 现象 | ThinkPHP 连接数据库失败，报 `Access denied for user 'root'@'localhost'` |
| 原因 | `config/database.php` 默认密码 `z123123`，但 `.env` 中 `DB_PASS=`（空）覆盖了默认值 |
| 解决 | 确保 `.env` 文件存在且配置正确 |

### 坑 5：OPcache 导致代码修改不生效

| 项目 | 内容 |
|------|------|
| 现象 | 修改 PHP 代码后请求结果没有变化 |
| 原因 | PHP OPcache 缓存了旧版本的中间件/控制器代码 |
| 解决 | 开发环境关闭 OPcache；或调用 `curl http://hotelx.local/api/online-data/clear-cache` |

### 坑 6：hotel-frontend/ 从未提交 Git

| 项目 | 内容 |
|------|------|
| 现象 | Vite 重构版源码无法通过 Git 恢复 |
| 原因 | 整个 `hotel-frontend/` 目录从未执行 `git add` 和 `git commit` |
| 影响 | 如果本地文件丢失，Vite 重构版源码无法恢复 |
| 建议 | 尽快将 hotel-frontend/ 纳入 Git 管理或合并到主项目 |

### 坑 7：PowerShell 中文路径编码问题

| 项目 | 内容 |
|------|------|
| 现象 | PowerShell `-File` 模式执行脚本时，中文路径显示为乱码（`妗岄潰`、`鍥介檯`）|
| 原因 | PowerShell 脚本编码与系统编码不一致 |
| 解决 | 改用 `cmd /c` 命令执行文件操作 |

---

## 三、报错与解决方案汇总

| # | 错误现象 | 根因 | 解决方案 |
|---|----------|------|----------|
| 1 | API 返回 403 "登录失效" | Cookie 过期 | 重新运行书签脚本获取新 Cookie |
| 2 | 美团 API 返回空数据 | 日期格式用了 YYYY-MM-DD | 代码中已将美团日期转为 YYYYMMDD |
| 3 | `public/index.html` 552 字节 | Vite build 覆盖 | `git checkout origin/main -- HOTEL/public/index.html` |
| 4 | 数据库 Access denied | .env 被删除或密码不一致 | 确认 .env 中 DB_PASS=空 |
| 5 | PHP 代码修改后不生效 | OPcache 缓存 | 关闭 OPcache 或调用 clear-cache 接口 |
| 6 | hotel-frontend/ 无法从 Git 恢复 | 从未提交 Git | 使用本地版本，尽快纳入 Git |
| 7 | PowerShell 中文路径乱码 | 脚本编码问题 | 改用 cmd /c 执行命令 |
| 8 | CORS 跨域报错 | 前端从非预期域名访问 | ThinkPHP 已配置 options 预检路由 |

---

## 四、废弃方案

### 废弃 1：ThinkPHP 模板渲染模式

- **废弃原因**：改为前后端分离架构，前端独立 SPA
- **废弃时间**：早期开发阶段
- **遗留**：app/view/ 目录和 route/app.php 中部分模板路由可能残留

### 废弃 2：多启动脚本

- **废弃文件**：`start.bat`、`start_php.bat`、`start_php_server.bat`、`start_server.bat`、`track_issue.bat`、`一站式酒店管理系统.bat`
- **废弃原因**：改用 XAMPP 统一管理 Apache + MySQL

### 废弃 3：Docker 部署方案

- **废弃文件**：`Dockerfile`、`docker-compose.yml`、`docker-deploy.md`
- **废弃原因**：转向 XAMPP 本地开发 + 标准 PHP 部署
- **建议**：如需生产部署，考虑用 Docker 但需要重新编写

### 废弃 4：多配置文件

- **废弃文件**：`php-local.ini`、`nginx.conf`
- **废弃原因**：统一使用 XAMPP 默认配置

### 废弃 5：导出脚本

- **废弃文件**：`export_mysql.php`
- **废弃原因**：改用 `hotelx_dump.sql` 直接导入

---

## 五、注意事项

### 数据库

- ✅ 始终确保 `.env` 文件存在
- ✅ 导入数据库：`mysql -u root hotelx < hotelx_dump.sql`
- ✅ 创建数据库：`CREATE DATABASE IF NOT EXISTS hotelx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
- ⚠️ 删除 `.env` 会导致 ThinkPHP 尝试用 `z123123` 连接数据库

### Git

- ✅ 修改 `public/index.html` 前确认当前工作目录干净，避免覆盖
- ⚠️ hotel-frontend/ 从未提交 Git，**严禁删除本地 `hotel-frontend/` 目录**
- ✅ 重要的代码修改应及时 commit 和 push

### API 开发

- ✅ 新增接口需要在 `route/app.php` 中注册路由
- ✅ 新增需要认证的接口时，路由分组需要挂载 `->middleware(\app\middleware\Auth::class)`
- ✅ 不需要认证的接口单独列出，不加入认证分组
- ⚠️ 美团 API 日期参数必须使用 `YYYYMMDD` 格式

### Cookie 管理

- ✅ 定期检查 Cookie 有效期
- ✅ 建议实现 Cookie 过期自动提醒功能
- ⚠️ 每次 OTA 平台界面改版，书签脚本可能需要同步更新

### PHP 开发

- ⚠️ 开发环境建议关闭 OPcache（`php.ini` 中设置 `opcache.enable=0`）
- ✅ 修改代码后如遇不生效，调用 `/api/online-data/clear-cache` 清除缓存
- ✅ 清除 ThinkPHP 运行时缓存：`rm -rf runtime/cache/* runtime/temp/*`

---

## 六、未完成项（TODO）

- [ ] **hotel-frontend/ 合并**：Vite 重构版合并到主项目并提交 Git
- [ ] **Cookie 过期预警**：自动提醒管理员重新授权
- [ ] **AI Agent LLM 接入**：对接具体 LLM API（ChatGPT / Claude / 阿里通义）
- [ ] **多租户 Saas 化**：数据库级别或 Schema 级别租户隔离
- [ ] **忘记密码功能**：当前无此功能，需管理员手动在数据库修改
- [ ] **移动端适配**：当前单文件前端在小屏幕设备体验差
- [ ] **API 文档**：Swagger/OpenAPI 规范化
- [ ] **美团 Cookie 书签更新**：可能需适配美团新版 ebooking 界面
- [ ] **竞对监控 UI 验证**：API 就绪但 UI 实际效果待验证
