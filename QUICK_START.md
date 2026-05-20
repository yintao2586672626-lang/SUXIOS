# 宿析OS 快速启动

## 项目基础信息

- 后端框架：ThinkPHP 8
- PHP 版本：PHP >= 8.0，推荐 PHP 8.2
- 数据库：MySQL
- 默认数据库名：hotelx
- Web 根目录：public
- 前端形态：public/index.html 单文件 Vue 3 CDN 页面
- 一键启动脚本：start-hotel.bat

## 环境准备

推荐使用 XAMPP：

- Apache
- MySQL
- PHP

项目会优先检测以下 PHP 路径：

```text
C:\xampp\php\php.exe
D:\xampp\php\php.exe
C:\php\php.exe
PATH 中的 php
```

## 数据库导入

仓库已包含完整初始化入口：

```text
database/init_full.sql
```

创建数据库：

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS hotelx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

导入数据：

```powershell
C:\xampp\mysql\bin\mysql.exe -u root hotelx < database/init_full.sql
```

如果 XAMPP 安装在 D 盘，请把命令中的 `C:\xampp` 改为 `D:\xampp`。

`hotelx_dump.sql` 是基础 dump；完整初始化必须使用 `database/init_full.sql`，它会补齐当前代码使用的迁移表和字段。

## 环境变量

复制示例配置：

```powershell
copy .example.env .env
```

本地默认配置：

```env
APP_DEBUG = true
DB_TYPE = mysql
DB_HOST = 127.0.0.1
DB_NAME = hotelx
DB_USER = root
DB_PASS =
DB_PORT = 3306
DB_CHARSET = utf8mb4
DB_PREFIX =
```

`.env` 不提交到 GitHub。

## 启动方式

### 方式一：一键启动

双击：

```text
start-hotel.bat
```

脚本会自动检查：

- PHP 是否存在
- MySQL 是否可连接
- hotelx 数据库是否存在
- 核心表是否已导入
- 8080 端口是否可用

默认访问：

```text
http://127.0.0.1:8080/
```

如果 8080 被其他程序占用，脚本会自动尝试 8081-8099。

### 方式二：手动启动

```powershell
C:\xampp\php\php.exe think run --host 127.0.0.1 --port 8080
```

访问：

```text
http://127.0.0.1:8080/
```

健康检查：

```text
http://127.0.0.1:8080/api/health
```

正常返回示例：

```json
{"status":"ok","time":"2026-05-08 07:23:11"}
```

## Windows 验证命令

在 PowerShell 中如果 `npm run ...` 被执行策略拦截，使用 `npm.cmd run ...`：

```powershell
npm.cmd run verify:p0-guards
npm.cmd run test:e2e:quick
```

`test:e2e:edge` 是边界输入深度扫描，耗时明显高于 quick 回归，按需单独运行。

如果 `composer` 不在 PATH，但 XAMPP PHP 可用，可直接运行本地 PHPUnit：

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never
```

当前 `type-check` 仅在仓库存在 `.ts` 或 `.d.ts` 输入时执行 TypeScript 编译；没有 TypeScript 输入时会显示 skipped，不能替代 `test:e2e:quick` 或 PHP 单元测试。

## 进程排查

如果多个端口都启动过开发服务，先确认当前项目的 PHP 进程：

```powershell
Get-CimInstance Win32_Process -Filter "name = 'php.exe'" | Select-Object ProcessId,CommandLine
```

只停止确认属于当前项目且不再使用的进程：

```powershell
Stop-Process -Id <ProcessId> -Force
```

## AI 与外部数据源配置

`.env` 只保存本机配置，不提交到 GitHub。启用 AI 或外部数据源前，至少确认：

```text
AI_CONFIG_SECRET
DEEPSEEK_API_KEY 或数据库中的 AI 模型密钥
OPENAI_API_KEY 或数据库中的 OpenAI 兼容模型密钥
AMAP_KEY / AMAP_WEB_API_KEY（需要外部地图和信号数据时）
CRON_TOKEN（需要定时抓取入口时）
```

`config/llm.php` 默认读取 DeepSeek 配置；如果没有 `DEEPSEEK_API_KEY`，相关 LLM 能力会依赖数据库中的 AI 模型配置或显式报配置缺失。

## 常见问题

### 未找到 PHP

安装 XAMPP，或手动修改 `start-hotel.bat` 中的 `PHP_PATH`。

### 未检测到 hotelx 数据库

先执行数据库创建和 `database/init_full.sql` 导入。

### 核心表缺失

说明数据库可能没有完整导入，重新确认 `database/init_full.sql` 是否导入成功。

### 8080-8099 端口均不可用

关闭占用端口的程序，或修改 `start-hotel.bat` 中的端口范围。
