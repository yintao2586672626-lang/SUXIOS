# 宿析OS（SuXi OS）

> 面向连锁酒店的 SaaS 管理平台 — 打通"线上数据 → 收益分析 → AI 决策 → 运维管理"全链路。

---

## 一、项目介绍

宿析OS 是一款酒店管理 SaaS 系统，支持：

- **多酒店数据隔离**与分级权限管理
- **携程/美团 OTA 数据自动抓取**（Cookie + 书签脚本体系）
- **动态配置的日报表与月任务填报**（含 Excel 导入导出）
- **三大 AI Agent**（智能员工 / 收益管理 / 资产运维）
- **竞对价格监控**与微信机器人预警
- **门店罗盘**（可定制可视化仪表盘）

---

## 二、技术栈

| 层级 | 技术 | 版本 |
|------|------|------|
| 后端框架 | ThinkPHP | 8.0+ |
| ORM | ThinkORM | 3.0 / 4.0 |
| 前端（当前） | Vue 3 + Vue Router + Tailwind CSS | CDN 加载 |
| 前端（重构版） | Vue 3 + Vite + TypeScript + Tailwind | `hotel-frontend/` |
| 数据库 | MySQL | 5.7+（`utf8mb4` 字符集） |
| PHP 版本 | PHP | 8.0+（推荐 8.2） |
| Web 服务器 | Apache (XAMPP) | — |
| PHP 依赖 | Composer | — |

---

## 三、项目结构

```
宿析OS/
├── HOTEL/                     # ThinkPHP 后端项目（主要交付物）
│   ├── app/                   # 应用代码
│   │   ├── controller/        # 16 个业务控制器
│   │   ├── model/           # 34 个数据模型
│   │   └── middleware/       # 认证中间件
│   ├── config/               # ThinkPHP 配置
│   ├── route/                # 路由定义
│   ├── public/               # Web 根目录
│   │   ├── index.html       # 前端 SPA（17,000 行）
│   │   └── .htaccess        # Apache URL 重写
│   ├── scripts/              # 定时脚本
│   ├── .env                  # 环境变量（数据库配置）
│   ├── .example.env         # 环境变量模板
│   ├── composer.json         # PHP 依赖
│   └── hotelx_dump.sql     # 数据库备份
│
└── hotel-frontend/           # Vite 重构版前端（⚠️ 从未提交 Git）
    ├── src/                 # 源码
    ├── dist/                # 构建产物
    └── node_modules/        # npm 依赖
```

---

## 四、环境要求

| 软件 | 版本 | 说明 |
|------|------|------|
| PHP | >= 8.0（推荐 8.2） | — |
| Composer | 最新 | — |
| MySQL | 5.7+ | `utf8mb4` 字符集 |
| Apache | 2.4+ | 需开启 `mod_rewrite` |
| Node.js | 16+ | 仅修改 hotel-frontend/ 时需要 |
| pnpm | 8+ | hotel-frontend/ 依赖管理 |

---

## 五、安装依赖

```bash
# 1. 安装 PHP 依赖
cd HOTEL/
composer install

# 2. 安装前端依赖（如需修改 hotel-frontend/）
cd hotel-frontend/
pnpm install
```

---

## 六、启动开发环境

### 6.1 配置虚拟主机（XAMPP）

编辑 `C:\xampp\apache\conf\extra\httpd-vhosts.conf`，添加：

```apache
<VirtualHost *:80>
    DocumentRoot "d:/桌面/国际/JD-main/HOTEL/public"
    ServerName hotelx.local
    <Directory "d:/桌面/国际/JD-main/HOTEL/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

编辑 `C:\Windows\System32\drivers\etc\hosts`，添加：

```
127.0.0.1 hotelx.local
```

### 6.2 初始化数据库

```bash
# 启动 XAMPP（Apache + MySQL）

# 创建数据库
mysql -u root -e "CREATE DATABASE IF NOT EXISTS hotelx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 导入数据
mysql -u root hotelx < HOTEL/hotelx_dump.sql
```

### 6.3 启动

```bash
# 启动 XAMPP Control Panel → 勾选 Apache + MySQL → Start

# 浏览器访问
http://hotelx.local/
# 或
http://localhost/HOTEL/public/
```

### 6.4 开发服务器（仅后端，无 Apache）

```bash
cd HOTEL/
"C:\xampp\php\php.exe" think run --port 8080
# 访问 http://localhost:8080/
```

---

## 七、构建命令

### 前端构建（hotel-frontend/）

```bash
cd hotel-frontend/
pnpm build
# 输出到 dist/ 目录
```

> ⚠️ **警告**：不要在 `HOTEL/public/` 目录下执行 `vite build`，否则会覆盖 `public/index.html`（17,000 行单文件前端）。

### PHP 依赖更新

```bash
cd HOTEL/
composer update
```

---

## 八、测试命令

> ⚠️ **当前项目没有自动化测试框架。**

**手动测试方式**：

```bash
# 1. 健康检查
curl http://hotelx.local/api/health

# 2. 登录测试
curl -X POST http://hotelx.local/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}'

# 3. 查看路由列表
cd HOTEL/
"C:\xampp\php\php.exe" think route:list
```

**测试脚本**（项目已有）：

- `test-login.ps1` — 登录接口测试
- `test-api.ps1` — API 通用测试

---

## 九、环境变量说明

复制 `.example.env` 为 `.env` 并配置：

| 变量名 | 说明 | 示例值 |
|--------|------|--------|
| `APP_DEBUG` | 调试模式 | `true` / `false` |
| `DB_TYPE` | 数据库类型 | `mysql` |
| `DB_HOST` | MySQL 主机 | `127.0.0.1` |
| `DB_NAME` | 数据库名 | `hotelx` |
| `DB_USER` | 数据库用户名 | `root` |
| `DB_PASS` | 数据库密码 | `（本地通常为空）` |
| `DB_PORT` | MySQL 端口 | `3306` |
| `DB_CHARSET` | 字符集 | `utf8mb4` |

---

## 十、常见问题

### Q1：登录后 API 返回 401 未授权

**原因**：Token 过期（24h 有效期）或 `.env` 文件不存在。
**解决**：`composer install` 后确保 `.env` 存在，重新登录。

### Q2：数据库连接失败 `Access denied`

**原因**：`config/database.php` 默认密码 `z123123`，但 `.env` 中 `DB_PASS=`（空）会覆盖。删除 `.env` 会导致连接失败。
**解决**：确保 `.env` 存在且 `DB_PASS=` 为空。

### Q3：修改 PHP 代码后不生效

**原因**：PHP OPcache 缓存了旧代码。
**解决**：关闭 OPcache 或调用 `GET /api/online-data/clear-cache`。

### Q4：携程/美团数据抓取返回 403

**原因**：Cookie 已过期（通常 1-7 天）。
**解决**：重新运行书签脚本获取新 Cookie。

### Q5：`public/index.html` 变成 552 字节

**原因**：在 `public/` 目录下执行了 `vite build`，Vite 覆盖了原始文件。
**解决**：`git checkout origin/main -- HOTEL/public/index.html`

### Q6：美团 API 返回空数据

**原因**：日期格式错误。美团要求 `YYYYMMDD`，携程要求 `YYYY-MM-DD`。
**解决**：代码中已做转换，如仍为空数据检查 Cookie 是否有效。

### Q7：vendor/autoload.php 不存在

**原因**：`composer install` 未成功执行。
**解决**：`cd HOTEL/ && composer install`

---

## 十一、Codex 接手指南

### 第一次接手项目时

1. 阅读 `PROJECT_HANDOFF.md` 了解完整项目背景
2. 阅读 `DEV_LOG.md` 了解历史踩坑记录
3. 复制 `CODEX_START_PROMPT.md` 内容给 Codex
4. 阅读 `AGENTS.md` 了解开发规范

### 开发规范（必读）

- 所有 API 路由在 `route/app.php` 中注册
- 新接口需要认证时必须挂载 `Auth::class` 中间件
- 美团 API 日期格式必须是 `YYYYMMDD`
- 禁止在 `HOTEL/public/` 目录下执行 vite build
- hotel-frontend/ 从未提交 Git，**禁止删除本地文件**

### 参考文档

| 文件 | 说明 |
|------|------|
| `PROJECT_HANDOFF.md` | 完整项目交接文档 |
| `DEV_LOG.md` | 开发日志（踩坑/决策/废弃方案） |
| `CODEX_START_PROMPT.md` | Codex 快速启动提示词 |
| `AGENTS.md` | Codex 开发规范与任务清单 |
| `route/app.php` | 所有 API 路由 |
| `app/controller/OnlineData.php` | 携程/美团抓取核心逻辑 |
| `app/controller/Agent.php` | AI Agent 核心逻辑 |
