# SUXIOS 宿析OS

宿析OS 是面向酒店线上经营的 OTA 数据证据、收益诊断与运营决策系统。当前版本以携程、美团、携程商旅等授权后台可见数据为输入，先固定采集证据与字段口径，再把收益、流量、转化、竞争圈、服务质量、广告和商旅表现转成待确认 AI 建议、运营动作和效果复盘；投前、筹建、开业、扩张和转让作为二期辅助判断入口保留。

## 当前产品边界

第一阶段聚焦一个主闭环：

```text
授权 OTA 可见数据 -> 采集证据 -> 字段目录 -> 标准事实 -> 收益/流量/转化/竞争圈诊断 -> 待确认 AI 建议 -> 人工确认与动作执行 -> 效果复盘 -> 经营规则沉淀
```

- AI 只承担诊断、解释和建议生成；关键动作必须进入人工确认、执行记录和复盘。
- OTA 字段命名优先沿用携程/美团后台页面和 i18n 语言包中的既有术语，例如预订订单数、预订销售额、在店间夜、列表页曝光量、详情页访客量、订单页访客量、订单提交人数、PSI 服务质量分。
- 携程 / Trip.com eBooking 中文前端翻译包只是语言资源和前端线索，不是业务数据；其中的按钮、提示语、节假日、国家地区和埋点代码不能直接进入经营诊断。
- OTA 指标默认是渠道口径；未接入 PMS/CRS、线下/直客订单、全量可售房和全量收入前，不写成全酒店出租率、ADR、RevPAR 或全渠道收入。
- 未绑定真实 OTA 执行字段、授权和回写结果前，不宣称自动调价、自动房态或自动投放已闭环。
- OTA 采集默认顺序见 `docs/ota_acquisition_decision_playbook.md`：已确认接口走后端直连 Cookie/API；接口不确定时用 CDP 临时监听；必须真实打开页面才有数据时再用门店 Profile + CDP 监听。
- OTA 术语与项目逻辑口径见 `docs/ota_i18n_terminology_logic.md`；携程字段目录和接口规则见 `docs/ctrip_capture_field_inventory.md`。

## 数据到决策逻辑

宿析OS 的核心不是展示更多报表，而是把可验证字段转成可执行、可复盘的经营动作。

| 环节 | 项目内含义 | 输出 |
|---|---|---|
| 采集证据 | 保存来源页面、接口 URL、Payload、Preview / Response、采集方式和失败原因 | 可审计采集记录 |
| 字段目录 | 按平台页面和 i18n 语言包统一字段命名，保留 source path 和口径；翻译包只作术语参考 | 标准字段与字段说明 |
| 标准事实 | 将订单、间夜、销售额、曝光、访客、转化、竞争圈、服务质量、广告、商旅数据标准化 | 可计算指标行 |
| 经营诊断 | 按收益、流量、转化、竞争圈、服务质量、广告和商旅拆分原因 | 诊断结论与阻塞项 |
| AI 建议 | 只基于已验证字段输出建议、目标指标、风险和复盘周期 | 待确认动作建议 |
| 运营复盘 | 对比执行前后 OTA 数据，记录达成、接近达成或失败原因 | 策略沉淀和投决参考 |

## 系统构思逻辑

宿析OS不是单点数据工具，而是一个经营反馈系统。系统设计必须先看清：

```text
输入 -> 标准化 -> 诊断 -> 决策 -> 执行 -> 复盘 -> 沉淀
```

- 输入：携程、美团、携程商旅等授权可见数据，以及日报、竞争圈/同圈对比、执行记录和投资测算数据。
- 标准化：保留来源、口径、时间、状态和失败原因，沉淀为可复用字段目录和标准事实。
- 诊断：按收益、流量、转化、竞争圈、服务质量、广告和商旅拆分原因，不混用口径。
- 决策：AI 给出证据链、目标指标、风险和复盘周期，不把建议写成已执行结果。
- 执行：运营动作必须可审批、可追踪、可回看执行前后指标。
- 复盘：用下一轮 OTA 数据验证动作效果，把有效策略沉淀到经营规则和投决参考中。

详细原则见 `docs/system_design_logic.md`。

《系统之美》复盘与 AI 性能稳定性落地清单见 `docs/system_beauty_ai_stability_review.md`。

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
