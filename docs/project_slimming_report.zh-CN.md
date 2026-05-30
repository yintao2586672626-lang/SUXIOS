# 项目瘦身报告

更新日期：2026-05-30

范围：本报告只处理本地运行产物、测试产物和可再生成缓存；不删除业务代码、验收文档、`.git` 历史、依赖锁文件或数据库备份。

当前执行状态：已新增受控瘦身脚本和 dry-run 清单；实际删除在当前 Windows / 沙箱环境中被文件系统权限拒绝，尚未释放磁盘空间。

## 当前体积判断

| 项目 | 体积 | 处理策略 |
|---|---:|---|
| `output/` | 约 215 MB | 可清理，属于本地测试/导出产物。 |
| `storage/ctrip_profile_*` / `storage/meituan_profile_*` | 约 34 MB | 可清理，属于本地浏览器登录态/采集 profile；清理后需重新登录。 |
| `.gstack/` | 约 7 MB | 可清理，属于本地辅助工具状态。 |
| `runtime/`、`test-results/`、`.pytest_cache/` | 约 1 MB | 可清理，属于运行和测试缓存。 |
| `database/backups/` | 约 212 MB | 本轮不自动清理；该目录还涉及 OTA 凭据形态字段，应放入后续安全整改。 |
| `node_modules/`、`vendor/` | 约 29 MB | 默认不清理；需要重新安装依赖时可显式清理。 |

## 已新增脚本

| 命令 | 作用 |
|---|---|
| `npm run slim:local:dry-run` | 只列出可清理目标和预计释放空间，不删除文件。 |
| `npm run slim:local` | 清理默认本地运行产物。 |
| `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/clean_project_local_artifacts.ps1 -Apply -IncludeDependencies` | 额外清理 `node_modules/` 和 `vendor/`，需要后续重新安装依赖。 |
| `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/clean_project_local_artifacts.ps1 -Apply -IncludeSensitiveBackups` | 额外清理 `database/backups/`；仅在完成凭据轮换/备份处置授权后使用。 |

## 默认清理清单

- `output/`
- `runtime/`
- `test-results/`
- `.pytest_cache/`
- `.gstack/`
- `storage/ctrip_profile_*`
- `storage/meituan_profile_*`
- `storage/*.log`

## 不自动清理

- `app/`
- `public/index.html`
- `route/`
- `config/`
- `tests/`
- `docs/`
- `.git/`
- `database/backups/`
- `node_modules/`
- `vendor/`

## 风险说明

- 清理 OTA 浏览器 profile 会释放空间，但会丢失本地登录态，需要重新登录平台。
- 清理依赖目录后必须重新执行 `npm ci` 和 `composer install`。
- 清理 `database/backups/` 前必须先完成凭据轮换或确认备份内容可删除。

## 本次执行结果

| 命令 | 结果 |
|---|---|
| `npm run slim:local:dry-run` | 通过，预计默认可释放约 256.99 MB。 |
| `npm run slim:local` | 未完成，当前环境对 `output/`、`.gstack/`、`runtime/`、`test-results/`、`storage/ctrip_profile_*` 返回 Access denied。 |
| `npm run review:functional-readiness` | 通过，103 structural checks。 |
| `git diff --check` | 通过。 |

## 后续处理建议

1. 关闭可能占用文件的浏览器、Playwright、PHP 服务、测试进程和编辑器预览。
2. 重新运行 `npm run slim:local`。
3. 如果仍返回 Access denied，在 Windows 文件资源管理器中确认目录权限后再执行。
4. 安全整改阶段再单独处理 `database/backups/`，不要把备份清理混入普通瘦身。
