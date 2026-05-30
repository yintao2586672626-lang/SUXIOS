# Codex Security 正式扫描授权说明

更新时间：2026-05-30

## 当前状态

项目已通过现有高风险安全脚本、GitHub Actions 依赖审计和发布包敏感路径检查，但尚未执行 Codex Security repo-wide 正式扫描。

## 已有证据

| 检查 | 当前证据 |
|---|---|
| 高风险安全脚本 | `php scripts/verify_high_risk_security.php` 通过 |
| PHP 依赖审计 | GitHub Actions 执行 `composer audit --no-interaction`，返回无安全公告 |
| Node 依赖审计 | GitHub Actions 执行 `npm audit --audit-level=moderate`，返回 0 漏洞 |
| 发布包敏感路径 | `.gitignore` 与 `.gitattributes` 已覆盖 `.env`、数据库备份、采集报告、截图资产 |
| 备份跟踪状态 | `git ls-files database/backups` 无输出 |

## 仍需授权

正式 repo-wide Codex Security 扫描需要授权 subagents。授权后应按以下阶段执行：

1. Threat model
2. Finding discovery
3. Validation
4. Attack-path analysis
5. Markdown / HTML final report

## 扫描完成标准

- 每个 in-scope 文件或 worklist row 有完成记录或明确 deferred / suppressed / not_applicable 原因。
- 每个候选 finding 有 discovery、validation、attack-path 记录，或明确 deferred 原因。
- 输出最终 markdown 和 HTML 报告。
- 报告中单独标注生产配置、OTA 凭证、AI 模型配置、租户隔离、文件导入、外部 HTTP 请求、报表导出、后台权限等高风险面。

## 不应替代正式扫描的内容

- `verify_high_risk_security.php`
- `npm audit`
- `composer audit`
- grep / rg 搜索
- 人工问题清单

这些是前置证据，不等于完整 repo-wide security-scan。
