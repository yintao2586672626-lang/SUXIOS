# 宿析OS（SuXi OS）项目交接文档

> 交接时间：2026-03-18
> 项目路径：`d:\桌面\国际\JD-main\HOTEL\`
> Git 远程：`https://github.com/yintao2586672626-lang/0412.git`
> 注意：`hotel-frontend/`（Vite 重构版）从未提交 Git，仅本地存在。

---

## 一、项目背景

### 1.1 项目定位

- **名称**：宿析OS（SuXi OS）
- **定位**：面向连锁酒店的 SaaS 管理平台，打通"线上数据 → 收益分析 → AI 决策 → 运维管理"全链路。
- **目标用户**：酒店业主、店长、前台运营、收益管理。

### 1.2 现状概览

| 项目 | 状态 | 说明 |
|------|------|------|
| 后端 ThinkPHP | ✅ 生产可用 | RESTful API 完整 |
| 前端（单文件版） | ⚠️ 可用但难维护 | `public/index.html`，17,000 行 |
| 前端（Vite 版） | ⚠️ 未合并 | `hotel-frontend/`，从未提交 Git |
| 数据库 | ✅ 运行中 | MySQL（XAMPP），12 张核心表 |
| 线上数据抓取 | ✅ 核心功能 | 携程/美团 Cookie 抓取已跑通 |
| AI Agent | ⚠️ 框架就绪 | 三 Agent 模块已建，LLM 接入待完成 |

---

## 二、技术架构

### 2.1 技术栈

```
展示层   ：Vue 3 (CDN) + Vue Router (CDN) + Tailwind CSS (CDN) + FontAwesome (CDN)
业务层   ：ThinkPHP 8.0 + ThinkORM 3.0/4.0
运行环境  ：PHP >= 8.0
Web 服务器 ：Apache (XAMPP)
数据库   ：MySQL (XAMPP)
PHP 依赖  ：Composer
前端构建  ：pnpm + Vite（hotel-frontend/ 版本，未合并）
```

### 2.2 目录结构

```
HOTEL/
├── app/
│   ├── controller/              # 16 个业务控制器
│   │   ├── Auth.php             # 登录/登出/改密/用户信息
│   │   ├── Base.php             # 基类（分页/响应封装）
│   │   ├── Hotel.php            # 酒店 CRUD
│   │   ├── User.php             # 用户管理
│   │   ├── RoleController.php   # 角色/权限管理
│   │   ├── DailyReport.php      # 日报表（含 Excel 导入导出/字段映射）
│   │   ├── MonthlyTask.php      # 月度任务
│   │   ├── OnlineData.php       # ⭐ 携程/美团数据抓取（最核心模块）
│   │   ├── Agent.php            # ⭐ AI Agent 系统（3 个 Agent）
│   │   ├── CompetitorApi.php    # 竞对价格监控
│   │   ├── ReportConfig.php     # 报表配置
│   │   ├── HotelFieldTemplate.php
│   │   ├── SystemConfigController.php
│   │   ├── OperationLogController.php
│   │   └── admin/               # 管理模块
│   │       ├── Compass.php              # 门店罗盘（可视化仪表盘）
│   │       ├── CompetitorHotelController.php
│   │       ├── CompetitorPriceLogController.php
│   │       ├── CompetitorDeviceController.php
│   │       └── CompetitorWechatRobotController.php
│   ├── model/                   # 34 个 Eloquent 模型
│   │   ├── 核心：User, Hotel, Role, DailyReport, MonthlyTask
│   │   ├── 线上数据：OnlineDailyData, OnlineCookies, CtripConfig, MeituanConfig
│   │   ├── Agent：AgentConfig, AgentTask, AgentWorkOrder, AgentConversation,
│   │   │          AgentLog, KnowledgeBase, KnowledgeCategory
│   │   ├── 资产运维：Device, DeviceCategory, DeviceMaintenance, MaintenancePlan
│   │   ├── 能耗：EnergyConsumption, EnergyBenchmark, EnergySavingSuggestion
│   │   ├── 收益：DemandForecast, PriceSuggestion, CompetitorAnalysis,
│   │   │         CompetitorHotel, CompetitorPriceLog, CompetitorDevice, RoomType
│   │   └── 其他：OperationLog, LoginLog, ReportConfig, HotelFieldTemplate,
│   │              SystemConfig, CompassLayout, FieldMapping
│   └── middleware/
│       └── Auth.php              # Token 验证中间件
├── config/
│   ├── database.php             # ThinkPHP 数据库配置
│   └── ...                      # 其他 ThinkPHP 配置
├── route/
│   └── app.php                  # 路由定义（639 行，核心路由表）
├── public/
│   ├── index.html               # ⭐ 前端 SPA（17,137 行，单文件 Vue 3）
│   └── assets/                  # Vite build 产物（遗留，已不使用）
├── scripts/
│   ├── auto_fetch_online_data.php  # 定时抓取脚本
│   ├── cron_fetch.sh                 # Linux cron 脚本
│   └── export_daily_report.py        # 导出日报 Python 脚本
├── .env                         # 环境变量（数据库配置）
├── composer.json                # PHP 依赖
├── hotelx_dump.sql              # MySQL 数据库备份（2.1 MB）
└── runtime/                     # ThinkPHP 运行时缓存
```

---

## 三、数据库结构

### 3.1 核心表（12 张）

| 表名 | 用途 |
|------|------|
| `users` | 用户：id, username, password, realname, role_id, hotel_id, status, token, last_login_time, last_login_ip, login_count |
| `hotels` | 酒店：id, name, city, address, star_level, room_count, status |
| `roles` | 角色：id, name, display_name, permissions (JSON) |
| `user_hotel_permissions` | 用户-酒店多对多权限：user_id, hotel_id, permissions (JSON) |
| `daily_reports` | 日报表：id, hotel_id, report_date, report_data (JSON), submitter_id |
| `monthly_tasks` | 月度任务：id, hotel_id, year, month, task_config (JSON) |
| `online_daily_data` | 线上每日数据（携程/美团抓取的原始数据） |
| `field_mappings` | 字段映射模板：field_name, display_name, category, source_type |
| `report_configs` | 报表配置：日报表字段定义（分类/类型/单位/排序/必填） |
| `system_configs` | 系统配置（Key-Value） |
| `system_config` | 系统配置（旧，历史遗留） |
| `operation_logs` | 操作日志（审计） |

### 3.2 扩展表（通过 Model 发现）

AgentConfig, AgentTask, AgentWorkOrder, AgentConversation, AgentLog, KnowledgeBase, KnowledgeCategory, Device, DeviceCategory, DeviceMaintenance, MaintenancePlan, EnergyConsumption, EnergyBenchmark, EnergySavingSuggestion, RoomType, CompetitorHotel, CompetitorPriceLog, CompetitorDevice, DemandForecast, PriceSuggestion, CompetitorAnalysis, CompassLayout, LoginLog, OnlineCookies, CtripConfig, MeituanConfig, HotelFieldTemplate

---

## 四、API 路由总览

> 几乎所有接口均通过 `\app\middleware\Auth::class` 中间件验证。

### 4.1 无需认证的接口

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/api/auth/login` | 登录 |
| `GET` | `/api/health` | 健康检查 |
| `POST` | `/api/online-data/receive-cookies` | 书签脚本回传 Cookie（URL token 验证） |
| `GET` | `/api/online-data/cron-trigger` | 定时任务触发（X-Cron-Token 验证） |
| `POST` | `/api/test-ctrip-fetch` | 携程测试接口（调试用） |

### 4.2 核心 API 分组

| 分组 | 前缀 | 功能 |
|------|------|------|
| 认证 | `api/auth/` | 登录/登出/用户信息/改密 |
| 酒店 | `api/hotels/` | 酒店 CRUD |
| 用户 | `api/users/` | 用户管理 |
| 角色 | `api/roles/` | 角色/权限管理 |
| 日报表 | `api/daily-reports/` | 日报表（含导入导出/字段映射/同比环比计算） |
| 月任务 | `api/monthly-tasks/` | 月度任务 |
| 报表配置 | `api/report-configs/` | 字段配置 |
| **线上数据** | `api/online-data/` | ⭐ 携程/美团数据抓取/配置/自动抓取/AI 分析 |
| AI | `api/ai/` | 筹建策略/模拟/可行性分析 |
| 竞对监控 | `api/competitor/` + `api/admin/competitor-hotels/` | 价格监控/企业微信机器人 |
| 门店罗盘 | `compass/` + `api/compass/` | 可视化仪表盘 |
| 操作日志 | `api/operation-logs/` | 审计日志 |

---

## 五、本地运行方式

### 5.1 环境要求

- PHP >= 8.0（建议 8.1+）
- Composer（安装 PHP 依赖）
- MySQL（通过 XAMPP 或独立安装）
- Apache（通过 XAMPP）
- Node.js + pnpm（仅修改 hotel-frontend/ Vite 版本时需要）

### 5.2 数据库配置

```bash
# 数据库名：hotelx
# 用户名  ：root
# 密码    ：（空）
# 主机    ：127.0.0.1
# 端口    ：3306
```

**配置位置**：`HOTEL/.env`（优先级最高）
```
DB_TYPE=mysql
DB_HOST=127.0.0.1
DB_NAME=hotelx
DB_USER=root
DB_PASS=
DB_PORT=3306
DB_CHARSET=utf8mb4
```

> ⚠️ `config/database.php` 默认密码是 `z123123`，但 `.env` 会覆盖为**空密码**。删除 `.env` 会导致连接失败。

### 5.3 启动步骤

```bash
# 1. 进入项目目录
cd "d:\桌面\国际\JD-main\HOTEL\"

# 2. 安装 PHP 依赖
composer install

# 3. 配置 Apache 虚拟主机（XAMPP）
# 编辑 C:\xampp\apache\conf\extra\httpd-vhosts.conf，添加：
# <VirtualHost *:80>
#     DocumentRoot "d:/桌面/国际/JD-main/HOTEL/public"
#     ServerName hotelx.local
# </VirtualHost>
# 编辑 C:\Windows\System32\drivers\etc\hosts，添加：
# 127.0.0.1 hotelx.local

# 4. 启动 XAMPP（Apache + MySQL）

# 5. 创建数据库
mysql -u root -e "CREATE DATABASE IF NOT EXISTS hotelx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 6. 导入数据库备份
mysql -u root hotelx < hotelx_dump.sql

# 7. 访问应用
# http://hotelx.local/
```

### 5.4 定时任务（自动抓取）

```bash
# Linux/Mac — 添加 crontab
crontab -e
# 每天早上 8:00 触发
0 8 * * * curl http://hotelx.local/api/online-data/cron-trigger

# Windows — 任务计划程序 或直接运行
php scripts/auto_fetch_online_data.php
```

### 5.5 常用开发命令

```bash
# 重新安装依赖
composer install

# 清除 ThinkPHP 缓存
rm -rf runtime/cache/*
rm -rf runtime/temp/*

# 查看路由列表
php think route:list

# 清除 OPcache
curl http://hotelx.local/api/online-data/clear-cache

# 查看数据库表
mysql -u root -e "USE hotelx; SHOW TABLES;"
```

---

## 六、重要业务规则

### 6.1 认证机制

- **Token 生成**：`Auth.php` → `generateToken()` → `hash('sha256', $userId . $time . $random . uniqid())`
- **Token 存储**：ThinkPHP Cache（文件缓存，默认 `runtime/cache/`）
- **Token 有效期**：24 小时
- **请求格式**：`Authorization: Bearer <token>`
- **多端登录**：新登录会使旧 token 失效（`cache('user_token_' . $userId, $token)`）

### 6.2 权限体系

| 角色 | 判断方法 | 权限范围 |
|------|----------|----------|
| 超级管理员 | `isSuperAdmin()` | 所有酒店 + 所有功能 |
| 酒店管理员 | `isHotelManager()` | 所属酒店 |
| 普通用户 | — | 由 `user_hotel_permissions.permissions` JSON 控制 |

**关键权限项**：`can_view_report`、`can_fill_daily_report`、`can_fill_monthly_task`、`can_edit_report`、`can_delete_report`

### 6.3 线上数据抓取流程（⭐ 核心业务）

```
1. 管理员在携程/美团 ebooking 平台登录
2. 运行书签脚本（Bookmarklet），捕获当前 Cookie
3. 书签脚本 → POST /api/online-data/receive-cookies → 系统保存 Cookie
4. 前端 → POST /api/online-data/fetch-ctrip (或 /fetch-meituan) → 携带 Cookie 请求 ebooking API
5. API 返回数据 → 解析并保存到 online_daily_data 表
```

**定时自动抓取**：
- `cronTrigger` → `autoFetch` 遍历所有配置好的 Cookie 自动执行
- `setFetchSchedule` 设置每日抓取计划（如每天 08:00）

**支持的抓取类型**：

| 平台 | 方法 | 接口路径 | 日期格式 |
|------|------|----------|----------|
| 携程 | 日竞对数据 | `/fetch-ctrip` | `YYYY-MM-DD` |
| 携程 | 流量数据 | `/fetch-ctrip-traffic` | `YYYY-MM-DD` |
| 携程 | 评论数据 | `/fetch-ctrip-comments` | `YYYY-MM-DD` |
| 美团 | 竞对排名 | `/fetch-meituan` | `YYYYMMDD` |
| 美团 | 流量数据 | `/fetch-meituan-traffic` | `YYYYMMDD` |
| 美团 | 评论数据 | `/fetch-meituan-comments` | `YYYYMMDD` |

> ⚠️ **美团 API 日期格式是 `YYYYMMDD`**，与携程的 `YYYY-MM-DD` 不同。代码中已做转换。

**书签脚本**：`OnlineData.php` 的 `bookmarklet()` 方法动态生成，注入当前系统的 `receive-cookies` 接口地址。

### 6.4 日报表字段映射

- `report_configs` 定义所有日报表字段（分类/字段名/显示名/类型/单位/排序/必填）
- `field_mappings` 建立"原始数据字段" → "系统字段"的映射关系
- `DailyReport.php` 的 `detail()` 从 `report_data` JSON 提取数据，计算同比/环比/完成率
- 支持 Excel 导入（`parseImport`）和导出（`export`）

### 6.5 AI Agent 系统

三个 Agent 模块（均在 `Agent.php` 中）：

| Agent | 类型常量 | 功能 | 核心模型 |
|-------|----------|------|----------|
| 智能员工 | `AGENT_TYPE_STAFF` (1) | 知识库问答、自动工单、对话管理 | AgentWorkOrder, AgentConversation |
| 收益管理 | `AGENT_TYPE_REVENUE` (2) | 竞对价格分析、定价建议、需求预测 | PriceSuggestion, DemandForecast |
| 资产运维 | `AGENT_TYPE_ASSET` (3) | 设备管理、能耗监控、维护计划 | Device, EnergyConsumption, MaintenancePlan |

Agent 任务状态流转：`STATUS_PENDING` → `STATUS_RUNNING` → `STATUS_COMPLETED` / `STATUS_FAILED`

### 6.6 门店罗盘

- 用户可在 `admin.Compass` 自定义仪表盘布局
- 布局数据保存到 `compass_layouts` 表
- 支持拖拽保存（`saveLayout`）

---

## 七、当前代码状态

### 7.1 各模块稳定性

| 模块 | 状态 | 说明 |
|------|------|------|
| 认证系统 | ✅ 稳定 | Token + Cache，功能完整 |
| 酒店/用户/角色管理 | ✅ 稳定 | 标准 CRUD，权限体系完善 |
| 日报表 | ✅ 稳定 | 含 Excel 导入导出、字段映射 |
| 线上数据抓取 | ✅ 稳定 | 携程/美团均可用，Cookie 管理完善 |
| AI Agent | ⚠️ 基本完成 | 框架就绪，LLM 接入待完成 |
| 竞对监控 | ⚠️ 基本完成 | API 就绪，UI 需验证 |
| 门店罗盘 | ⚠️ 基本完成 | 自定义布局功能 |
| 设备/能耗管理 | ⚠️ 有模型 | Controller 可能需完善 |
| 前端（单文件版） | ⚠️ 技术债 | 17,000 行单文件，难维护 |
| 前端（Vite 版） | ⚠️ 未提交 | hotel-frontend/ 从未合并到主项目 |

### 7.2 前端现状分析

**21 个主页面**（通过 `currentPage === 'xxx'` 在单文件中切换）：

- **基础管理**：hotels、users、roles、system-config、data-config、operation-logs
- **核心业务**：daily-reports、monthly-tasks、report-config、hotel-investment
- **数据获取**：ctrip-ebooking（8 子Tab）、meituan-ebooking（8 子Tab）、online-data（8 子Tab）
- **AI能力**：ai-strategy、ai-simulation、ai-feasibility、agent-center
- **其他**：compass、lifecycle、ops-track、competitor

**状态量规模**：~66 个 ref、~11 个 computed，全部暴露在同一个 `setup()` return 中，无命名空间隔离。

### 7.3 Vite 重构版（hotel-frontend/）

已有完整的迁移计划（见 `c:\Users\...\plan.md`），计划迁移到 Vue 3 + Vite + TypeScript + Tailwind CSS，21 个页面拆分为独立 `.vue` 组件，建立 API 层、Pinia 状态管理、TypeScript 类型定义。**当前状态：从未提交 Git，源码仅本地存在。**

### 7.4 配置文件

| 文件 | 用途 |
|------|------|
| `.env` | 数据库连接、应用密钥 |
| `config/database.php` | ThinkPHP 数据库配置（默认密码 z123123，可被 .env 覆盖） |
| `config/app.php` | 应用配置（URL、时区等） |
| `route/app.php` | 路由定义 |

---

## 八、后续开发计划

### 8.1 短期（1-2 周）

- [ ] 合并 hotel-frontend/ Vite 重构版到主项目，确保功能对等
- [ ] 完善 AI Agent：对接具体 LLM API（建议 ChatGPT / Claude / 阿里通义）
- [ ] 实现 Cookie 过期预警机制，自动通知管理员重新授权
- [ ] 根据实际使用逐一修复 Bug

### 8.2 中期（1-2 个月）

- [ ] 多租户 Saas 化（数据库级别或 Schema 级别隔离）
- [ ] 数据可视化升级：门店罗盘升级为可配置图表系统（ECharts）
- [ ] 移动端适配
- [ ] API 文档规范化（Swagger/OpenAPI）

### 8.3 长期愿景

- [ ] 智能收益管理：基于历史数据和 AI 预测自动生成最优定价策略
- [ ] 智能能耗优化：AI 自动给出节能建议
- [ ] 开放平台：对外提供 API，让第三方 PMS 系统接入
