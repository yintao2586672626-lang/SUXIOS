# 生产入口与仓库结构清理说明

更新时间：2026-05-17

## 已确认的结构清理

| 范围 | 处理结果 | 判断依据 |
|---|---|---|
| 历史 `HOTEL/` 前缀树 | 从 Git 索引移除 | 当前项目根目录已经是 `HOTEL`，保留内层 `HOTEL/` 会形成重复源码树 |
| `public/assets/` | 从 Git 索引移除并加入 `.gitignore` | 当前 `public/index.html` 未引用，属于旧 Vite 构建产物 |
| `public/app-main.*`、`public/app.js`、`public/app-styles.css` | 从 Git 索引移除并忽略 | 已标记为前端过渡文件，当前入口未加载 |
| `public/components.css`、`public/enhanced-components.css`、`public/tailwind-custom.css` | 从 Git 索引移除并忽略 | 当前入口未加载，避免多套样式入口并存 |
| 一次性补丁/修复脚本 | 从 Git 索引移除 | 属于本地修复残留，不是运行依赖 |

## 生产入口清理

已从 `route/app.php` 移除以下开发/调试入口：

- `api/test-ctrip-fetch`
- `api/db-test`
- `api/test-ctrip-save`
- `api/test-ctrip-save-direct`
- `api/test-ctrip-config-list`
- `api/test-ctrip-config-delete`
- `api/test-save`
- `api/online-data/clear-cache`

保留并验证以下生产入口：

- `api/health`
- `api/online-data/receive-cookies`
- `api/online-data/cron-trigger`
- `api/agent/feasibility-report/generate`

## 已完成的拆分

| 文件 | 结果 |
|---|---|
| `public/index.html` | 大块内联 CSS 已抽到 `public/style.css` |
| `app/controller/OnlineData.php` | 携程流量 URL 归一化逻辑已抽到 `app/service/OtaTrafficUrlNormalizer.php` |

## 验证命令

```powershell
node scripts/verify_production_entry_hygiene.mjs
& 'C:\xampp\php\php.exe' scripts\verify_ota_traffic_url_normalizer.php
& 'C:\xampp\php\php.exe' -l route\app.php
& 'C:\xampp\php\php.exe' -l app\controller\OnlineData.php
& 'C:\xampp\php\php.exe' -l app\service\OtaTrafficUrlNormalizer.php
```
