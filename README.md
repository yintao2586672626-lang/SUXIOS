# SUXIOS 宿析OS

宿析OS 是面向酒店运营、收益分析、OTA 数据管理和门店驾驶舱的 ThinkPHP 8 项目。

## 当前产品边界

第一阶段聚焦一个主闭环：

```text
OTA 数据同步 -> 收益诊断 -> 运营建议 -> 动作追踪 -> 效果复盘
```

- AI 作为诊断、解释和建议生成能力嵌入主闭环，不单独扩展为独立产品线。
- 筹建、开业、扩张、转让、投资测算保留现有入口和记录能力，按二期辅助模块管理。
- 未绑定真实 OTA 执行字段、授权和回写结果前，不宣称自动调价、自动房态或自动投放已闭环。
- OTA 采集默认顺序见 `docs/ota_acquisition_decision_playbook.md`：已确认接口走后端直连 Cookie/API；接口不确定时用 CDP 临时监听；必须真实打开页面才有数据时再用 Profile + CDP 兜底。

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

仓库已包含完整初始化入口：

```text
database/init_full.sql
```

导入示例：

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS hotelx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
C:\xampp\mysql\bin\mysql.exe -u root hotelx < database/init_full.sql
```

如果 XAMPP 安装在 D 盘，请把命令中的 `C:\xampp` 改为 `D:\xampp`。

`database/hotel_admin_mysql.sql` 是可提交的基础 dump；`database/init_full.sql` 会继续加载登录日志、投诉表和所有迁移，覆盖当前代码使用的表与字段。

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
database/hotel_admin_mysql.sql 基础数据库备份
database/init_full.sql 完整数据库初始化入口
start-hotel.bat   Windows 一键启动脚本
QUICK_START.md    下载后运行说明
```
