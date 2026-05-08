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

仓库已包含数据库备份：

```text
hotelx_dump.sql
```

创建数据库：

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS hotelx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

导入数据：

```powershell
C:\xampp\mysql\bin\mysql.exe -u root hotelx < hotelx_dump.sql
```

如果 XAMPP 安装在 D 盘，请把命令中的 `C:\xampp` 改为 `D:\xampp`。

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

## 常见问题

### 未找到 PHP

安装 XAMPP，或手动修改 `start-hotel.bat` 中的 `PHP_PATH`。

### 未检测到 hotelx 数据库

先执行数据库创建和 `hotelx_dump.sql` 导入。

### 核心表缺失

说明数据库可能没有完整导入，重新确认 `hotelx_dump.sql` 是否导入成功。

### 8080-8099 端口均不可用

关闭占用端口的程序，或修改 `start-hotel.bat` 中的端口范围。
