# SUXIOS 宿析OS

宿析OS 是面向酒店运营、收益分析、OTA 数据管理和门店驾驶舱的 ThinkPHP 8 项目。

## 项目基础信息

- 后端：ThinkPHP 8
- PHP：>= 8.0，推荐 8.2
- 数据库：MySQL
- 默认数据库名：hotelx
- Web 根目录：public
- 前端：public/index.html 单文件 Vue 3 CDN 页面
- 一键启动：start-hotel.bat

## 快速启动

完整启动步骤见：

```text
QUICK_START.md
```

Windows 本地可直接双击：

```text
start-hotel.bat
```

默认访问：

```text
http://127.0.0.1:8080/
```

健康检查：

```text
http://127.0.0.1:8080/api/health
```

## 数据库

仓库已包含数据库备份：

```text
hotelx_dump.sql
```

导入示例：

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS hotelx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
C:\xampp\mysql\bin\mysql.exe -u root hotelx < hotelx_dump.sql
```

如果 XAMPP 安装在 D 盘，请把命令中的 `C:\xampp` 改为 `D:\xampp`。

## 配置

复制环境变量模板：

```powershell
copy .example.env .env
```

`.env` 不提交到 GitHub。

## 目录说明

```text
app/              ThinkPHP 应用代码
config/           项目配置
route/            路由配置
public/           Web 根目录和前端页面
database/         SQL 资源和迁移脚本
hotelx_dump.sql   数据库备份
start-hotel.bat   Windows 一键启动脚本
QUICK_START.md    下载后运行说明
```
